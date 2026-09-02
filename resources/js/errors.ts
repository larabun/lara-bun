export class ServerValidationError extends Error {
  public readonly errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]>) {
    super(message);
    this.name = "ServerValidationError";
    this.errors = errors;
  }
}

/** An action answered with a location instead of a result. */
export class ServerRedirectError extends Error {
  public readonly location: string;

  constructor(location: string) {
    super(`Server action redirected to ${location}`);
    this.name = "ServerRedirectError";
    this.location = location;
  }
}

export class ServerAuthenticationError extends Error {
  constructor(message: string = "Unauthenticated.") {
    super(message);
    this.name = "ServerAuthenticationError";
  }
}

export class ServerDumpError extends Error {
  constructor() {
    super("Server returned a dump response.");
    this.name = "ServerDumpError";
  }
}

export class ServerSessionExpiredError extends Error {
  constructor(message: string = "Your session has expired. Please refresh the page.") {
    super(message);
    this.name = "ServerSessionExpiredError";
  }
}

/**
 * Turn a failed server-action response into the error it describes.
 *
 * A server action that does not succeed answers with JSON or a redirect
 * header rather than a Flight stream. Passing one of those to the Flight
 * decoder does not produce the server's message — it produces an internal
 * parser failure ("enqueueModel is not a function") or a truncated read
 * ("Connection closed."), which is what reached onError before this existed.
 *
 * Returns without throwing when the response is a stream to be decoded.
 */
export async function throwForFailedAction(response: Response): Promise<void> {
  if (response.ok) return;

  // Auth and explicit redirects travel as a header, whatever the status.
  const location = response.headers.get("X-RSC-Redirect");

  if (location !== null && location !== "") {
    throw new ServerRedirectError(location);
  }

  if (response.status === 422) {
    const payload = (await response.json().catch(() => null)) as
      | { message?: string; errors?: Record<string, string[]> }
      | null;

    throw new ServerValidationError(
      payload?.message ?? "Validation failed",
      payload?.errors ?? {},
    );
  }

  throw new Error(`Server action failed with ${response.status}`);
}
