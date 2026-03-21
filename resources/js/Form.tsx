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

interface FormRenderProps<T extends Record<string, unknown> = Record<string, unknown>> {
  pending: boolean;
  data: T;
  errors: Record<string, string[]>;
  error: (field: keyof T & string) => string | undefined;
  clearErrors: (...fields: (keyof T & string)[]) => void;
  reset: () => void;
}

interface FormProps<T extends Record<string, unknown> = Record<string, unknown>>
  extends Omit<FormHTMLAttributes<HTMLFormElement>, "action" | "method" | "children"> {
  action: string | ((formData: FormData) => Promise<unknown>);
  method?: "get" | "post";
  prefetch?: PrefetchStrategy;
  cacheFor?: number;
  replace?: boolean;
  preserveScroll?: boolean;
  resetOnSuccess?: boolean;
  /** Called inside the transition with typed form data. Use it to call your useOptimistic setter. */
  optimistic?: (data: T) => void;
  onSuccess?: (result: unknown) => void;
  onError?: (errors: Record<string, string[]>) => void;
  onSubmit?: (formData: FormData) => void | false;
  children: ReactNode | ((form: FormRenderProps<T>) => ReactNode);
}

const FormStatusContext = createContext<FormRenderProps>({
  pending: false,
  data: {},
  errors: {},
  error: () => undefined,
  clearErrors: () => {},
  reset: () => {},
});

export function useFormStatus<T extends Record<string, unknown> = Record<string, unknown>>(): FormRenderProps<T> {
  return useContext(FormStatusContext) as FormRenderProps<T>;
}

function formDataToObject<T extends Record<string, unknown>>(formData: FormData): T {
  const obj: Record<string, unknown> = {};
  for (const [key, value] of formData.entries()) {
    if (typeof value === "string") {
      obj[key] = value;
    }
  }
  return obj as T;
}

export default function Form<T extends Record<string, unknown> = Record<string, unknown>>({
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
}: FormProps<T>) {
  const isGetForm = typeof action === "string";
  const method = methodProp ?? (isGetForm ? "get" : "post");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [currentData, setCurrentData] = useState<T>({} as T);
  const [isPending, startTransition] = useTransition();
  const formRef = useRef<HTMLFormElement>(null);

  const error = useCallback(
    (field: keyof T & string): string | undefined => errors[field]?.[0],
    [errors]
  );

  const clearErrors = useCallback(
    (...fields: (keyof T & string)[]) => {
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
    setCurrentData({} as T);
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
      const data = formDataToObject<T>(formData);
      setCurrentData(data);

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
          optimistic?.(data);

          const result = await serverAction(formData);

          if (resetOnSuccess) {
            formRef.current?.reset();
            setCurrentData({} as T);
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

  const formStatus: FormRenderProps<T> = {
    pending: isPending,
    data: currentData,
    errors,
    error,
    clearErrors,
    reset: resetForm,
  };

  return (
    <FormStatusContext.Provider value={formStatus as FormRenderProps}>
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
