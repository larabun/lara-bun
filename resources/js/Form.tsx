"use client";

import {
  type FormHTMLAttributes,
  type FormEvent,
  type ReactNode,
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  useTransition,
} from "react";
import { ServerValidationError } from "./errors";

type PrefetchStrategy = "hover" | "mount" | "none";

interface FormRenderProps {
  pending: boolean;
  errors: Record<string, string[]>;
  error: (field: string) => string | undefined;
  clearErrors: (...fields: string[]) => void;
  reset: () => void;
}

interface FormProps extends Omit<FormHTMLAttributes<HTMLFormElement>, "action" | "method" | "children"> {
  action: string | ((formData: FormData) => Promise<unknown>);
  method?: "get" | "post";
  prefetch?: PrefetchStrategy;
  cacheFor?: number;
  replace?: boolean;
  preserveScroll?: boolean;
  resetOnSuccess?: boolean;
  /** Called inside the transition with form data. Use it to call your useOptimistic setter. */
  optimistic?: (data: Record<string, string>) => void;
  onSuccess?: (result: unknown) => void;
  onError?: (errors: Record<string, string[]>) => void;
  onSubmit?: (formData: FormData) => void | false;
  children: ReactNode | ((form: FormRenderProps) => ReactNode);
}

const FormStatusContext = createContext<FormRenderProps>({
  pending: false,
  errors: {},
  error: () => undefined,
  clearErrors: () => {},
  reset: () => {},
});

export function useFormStatus(): FormRenderProps {
  return useContext(FormStatusContext);
}

function formDataToObject(formData: FormData): Record<string, string> {
  const obj: Record<string, string> = {};
  for (const [key, value] of formData.entries()) {
    if (typeof value === "string") {
      obj[key] = value;
    }
  }
  return obj;
}

export default function Form({
  action,
  method: methodProp,
  prefetch = "none",
  cacheFor,
  replace = false,
  preserveScroll = false,
  resetOnSuccess = true,
  optimistic,
  onSuccess,
  onError,
  onSubmit,
  children,
  ...rest
}: FormProps) {
  const isGetForm = typeof action === "string";
  const method = methodProp ?? (isGetForm ? "get" : "post");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [isPending, startTransition] = useTransition();
  const formRef = useRef<HTMLFormElement>(null);

  const error = useCallback(
    (field: string): string | undefined => errors[field]?.[0],
    [errors]
  );

  const clearErrors = useCallback(
    (...fields: string[]) => {
      if (fields.length === 0) {
        setErrors({});
      } else {
        setErrors((prev) => {
          const next = { ...prev };
          for (const f of fields) {
            delete next[f];
          }
          return next;
        });
      }
    },
    []
  );

  const resetForm = useCallback(() => {
    formRef.current?.reset();
    setErrors({});
  }, []);

  useEffect(() => {
    if (isGetForm && prefetch === "mount") {
      const fn = (window as any).__rsc_prefetch;
      fn?.(action, cacheFor);
    }
  }, [isGetForm, prefetch, action, cacheFor]);

  const doPrefetch = useCallback(() => {
    if (!isGetForm) return;
    const fn = (window as any).__rsc_prefetch;
    fn?.(action as string, cacheFor);
  }, [isGetForm, action, cacheFor]);

  const handleSubmit = useCallback(
    (e: FormEvent<HTMLFormElement>) => {
      e.preventDefault();
      const formData = new FormData(e.currentTarget);

      if (onSubmit?.(formData) === false) {
        return;
      }

      if (isGetForm && method === "get") {
        const url = new URL(action as string, window.location.origin);
        for (const [key, value] of formData.entries()) {
          if (typeof value === "string" && value !== "") {
            url.searchParams.set(key, value);
          }
        }

        const path = url.pathname + url.search;
        const nav = (window as any).__rsc_navigate;
        nav?.(path, { replace, preserveScroll });
        return;
      }

      const serverAction = action as (formData: FormData) => Promise<unknown>;

      setErrors({});
      startTransition(async () => {
        try {
          // Call optimistic updater inside the transition so React's
          // useOptimistic picks it up and auto-reverts on settle.
          optimistic?.(formDataToObject(formData));

          const result = await serverAction(formData);

          if (resetOnSuccess) {
            formRef.current?.reset();
          }

          setErrors({});
          onSuccess?.(result);
        } catch (err) {
          if (err instanceof ServerValidationError) {
            setErrors(err.errors);
            onError?.(err.errors);
          } else {
            throw err;
          }
        }
      });
    },
    [action, isGetForm, method, replace, preserveScroll, resetOnSuccess, optimistic, onSubmit, onSuccess, onError]
  );

  const formStatus: FormRenderProps = {
    pending: isPending,
    errors,
    error,
    clearErrors,
    reset: resetForm,
  };

  return (
    <FormStatusContext.Provider value={formStatus}>
      <form
        ref={formRef}
        onSubmit={handleSubmit}
        onMouseEnter={prefetch === "hover" ? doPrefetch : undefined}
        data-pending={isPending ? "" : undefined}
        {...rest}
      >
        {typeof children === "function" ? children(formStatus) : children}
      </form>
    </FormStatusContext.Provider>
  );
}
