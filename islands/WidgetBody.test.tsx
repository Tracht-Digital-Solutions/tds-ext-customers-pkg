// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import WidgetBody from "./WidgetBody";

/**
 * The directory-count widget. `—` on a failure, never `0`: the latter claims
 * the customer directory is empty, which for this tile is the difference
 * between "nothing to see" and "we could not ask".
 */

let reply: { status: number; body: unknown } | "reject" = { status: 200, body: { count: 0 } };

beforeEach(() => {
  reply = { status: 200, body: { count: 0 } };
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      if (reply === "reject") throw new TypeError("offline");
      return {
        ok: reply.status < 300,
        status: reply.status,
        json: async () => (reply === "reject" ? {} : reply.body),
      } as Response;
    }),
  );
});

afterEach(() => cleanup());

describe("the widget", () => {
  it("fetches its summary endpoint with credentials", () => {
    render(<WidgetBody />);
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(fetchMock.mock.calls[0]![0]).toBe("/customers/summary");
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a placeholder before the request resolves", () => {
    render(<WidgetBody />);
    expect(screen.getByText("…")).toBeTruthy();
  });

  it("renders the directory count", async () => {
    reply = { status: 200, body: { count: 23 } };
    render(<WidgetBody />);
    expect(await screen.findByText("23")).toBeTruthy();
    expect(screen.getByText("Kunden im Verzeichnis")).toBeTruthy();
  });

  it("renders a real zero for an empty directory", async () => {
    render(<WidgetBody />);
    expect(await screen.findByText("0")).toBeTruthy();
  });

  it("shows a dash on an error rather than claiming the directory is empty", async () => {
    reply = { status: 500, body: {} };
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
  });

  it("shows a dash when the request rejects", async () => {
    reply = "reject";
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
  });

  it("does not render a count carried by a NON-OK response", async () => {
    reply = { status: 403, body: { count: 99 } };
    render(<WidgetBody />);
    expect(await screen.findByText("—")).toBeTruthy();
    expect(screen.queryByText("99")).toBeNull();
  });
});
