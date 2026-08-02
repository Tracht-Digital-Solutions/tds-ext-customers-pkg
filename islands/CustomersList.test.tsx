// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CustomersList from "./CustomersList";

/**
 * The canonical customer/company directory — the list that backs membership
 * editing, billing and the portal. Everything downstream keys off these rows,
 * so the assertions concentrate on:
 *
 *  - **an edit PATCHes the row it opened**, never POSTing a second copy. A
 *    duplicate company here silently splits one customer's invoices, portal
 *    access and tickets across two ids;
 *  - **a 409 says "E-Mail bereits vergeben"**, not a generic error — that is
 *    the one failure the admin can actually fix, and it means the customer
 *    already exists under another row;
 *  - **delete hits the id it was asked for**, and does not refresh the list
 *    when it failed (which would look like the row simply vanished).
 *
 * Error-path tests deliberately answer with a POPULATED body and a non-OK
 * status: against an empty error body the `res.ok` check is unobservable.
 */

interface Reply {
  status: number;
  body: unknown;
}
type Handler = (url: string, init?: RequestInit) => Reply | undefined;

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let handlers: Handler[] = [];

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(url)) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

const ACME = { id: 5, name: "Acme GmbH", email: "info@acme.de", phone: "040 123", note: "Bestandskunde" };
const BETA = { id: 6, name: "Beta AG", email: null, phone: null, note: null };

beforeEach(() => {
  calls = [];
  handlers = [() => ({ status: 200, body: {} })];
  respond(/^\/customers$/, { customers: [] });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = handlers.map((h) => h(url, init)).find((r) => r !== undefined)!;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => cleanup());

const user = () => userEvent.setup({ delay: null });
const sent = (method: string, match: RegExp) => calls.filter((c) => c.method === method && match.test(c.url));

async function open(customers: unknown[] = []) {
  respond(/^\/customers$/, { customers }, 200, "GET");
  render(<CustomersList />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  await screen.findByRole("table");
  return u;
}

const row = (name: string) => screen.getAllByRole("row").find((r) => r.textContent!.includes(name))!;
const nameBox = () => screen.getByPlaceholderText("Name / Firma") as HTMLInputElement;
const emailBox = () => screen.getByPlaceholderText("E-Mail (optional)") as HTMLInputElement;
const phoneBox = () => screen.getByPlaceholderText("Telefon (optional)") as HTMLInputElement;
const noteBox = () => screen.getByPlaceholderText("Notiz (optional)") as HTMLTextAreaElement;

describe("loading", () => {
  it("reads the directory with credentials", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(fetchMock.mock.calls[0]![0]).toBe("/customers");
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the directory arrives", () => {
    render(<CustomersList />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("says so when the directory is empty", async () => {
    await open();
    expect(screen.getByText("Noch keine Kunden.")).toBeTruthy();
  });

  it("lists each customer with contact details IN THEIR OWN COLUMNS", async () => {
    // Checking only that both strings appear somewhere in the row passes even
    // when the email and phone columns are swapped.
    await open([ACME]);
    const cells = within(row("Acme GmbH")).getAllByRole("cell");
    expect(cells[0]!.textContent).toBe("Acme GmbH");
    expect(cells[1]!.textContent).toBe("info@acme.de");
    expect(cells[2]!.textContent).toBe("040 123");
  });

  it("shows dashes for missing contact details", async () => {
    await open([BETA]);
    expect(within(row("Beta AG")).getAllByText("—")).toHaveLength(2);
  });

  it("names the reason when the user lacks permission", async () => {
    respond(/^\/customers$/, {}, 403, "GET");
    render(<CustomersList />);
    expect(await screen.findByText("Keine Berechtigung.")).toBeTruthy();
  });

  it("treats an expired session the same way", async () => {
    respond(/^\/customers$/, {}, 401, "GET");
    render(<CustomersList />);
    expect(await screen.findByText("Keine Berechtigung.")).toBeTruthy();
  });

  it("reports any other failure with its status", async () => {
    respond(/^\/customers$/, {}, 500, "GET");
    render(<CustomersList />);
    expect(await screen.findByText("Fehler (HTTP 500).")).toBeTruthy();
  });

  it("does NOT list customers carried by a non-OK response", async () => {
    // A denied response must not put the customer directory on screen.
    respond(/^\/customers$/, { customers: [ACME] }, 403, "GET");
    render(<CustomersList />);
    await screen.findByText("Keine Berechtigung.");
    expect(screen.queryByText("Acme GmbH")).toBeNull();
  });

  it("leaves the loading state even when the request fails", async () => {
    respond(/^\/customers$/, {}, 500, "GET");
    render(<CustomersList />);
    await screen.findByText("Fehler (HTTP 500).");
    expect(screen.queryByLabelText("Wird geladen")).toBeNull();
  });

  it("tolerates a response with no customers field", async () => {
    respond(/^\/customers$/, {}, 200, "GET");
    render(<CustomersList />);
    expect(await screen.findByText("Noch keine Kunden.")).toBeTruthy();
  });
});

describe("creating a customer", () => {
  it("hides the form until it is asked for", async () => {
    await open();
    expect(screen.queryByPlaceholderText("Name / Firma")).toBeNull();
    expect(screen.getByRole("button", { name: "Neuer Kunde" })).toBeTruthy();
  });

  it("opens a blank form", async () => {
    const u = await open([ACME]);
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    expect(screen.getByRole("heading", { name: "Neuer Kunde" })).toBeTruthy();
    expect(nameBox().value).toBe("");
  });

  it("refuses to create a nameless customer", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(emailBox(), "neu@example.de");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Name ist erforderlich.")).toBeTruthy();
    expect(sent("POST", /customers/)).toHaveLength(0);
  });

  it("treats a whitespace-only name as empty", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "   ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Name ist erforderlich.")).toBeTruthy();
    expect(sent("POST", /customers/)).toHaveLength(0);
  });

  it("POSTs a new customer to the collection", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.type(emailBox(), "neu@example.de");
    await u.type(phoneBox(), "040 999");
    await u.type(noteBox(), "Über Empfehlung");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("POST", /^\/customers$/)).toHaveLength(1));
    expect(sent("POST", /^\/customers$/)[0]!.body).toEqual({
      name: "Neu GmbH",
      email: "neu@example.de",
      phone: "040 999",
      note: "Über Empfehlung",
    });
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "POST")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("closes the form and reloads on success", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Gespeichert.")).toBeTruthy();
    expect(screen.queryByPlaceholderText("Name / Firma")).toBeNull();
    await waitFor(() => expect(sent("GET", /^\/customers$/)).toHaveLength(2));
  });

  it("abandons the form without sending anything", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Abbrechen" }));
    expect(screen.queryByPlaceholderText("Name / Firma")).toBeNull();
    expect(sent("POST", /customers/)).toHaveLength(0);
  });
});

describe("editing a customer", () => {
  it("loads the row into the form", async () => {
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Bearbeiten" }));
    expect(screen.getByRole("heading", { name: "Kunde bearbeiten" })).toBeTruthy();
    expect(nameBox().value).toBe("Acme GmbH");
    expect(emailBox().value).toBe("info@acme.de");
    expect(phoneBox().value).toBe("040 123");
    expect(noteBox().value).toBe("Bestandskunde");
  });

  it("turns nulls into empty strings so the inputs stay controlled", async () => {
    // The DOM hides this — a `value={null}` input still reads back as "". The
    // difference only shows in what gets SAVED, and the create path sends ""
    // (from `empty`), so an edit of a contactless row must match it.
    const u = await open([BETA]);
    await u.click(within(row("Beta AG")).getByRole("button", { name: "Bearbeiten" }));
    expect(emailBox().value).toBe("");
    expect(phoneBox().value).toBe("");
    expect(noteBox().value).toBe("");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/customers\/6$/)).toHaveLength(1));
    expect(sent("PATCH", /^\/customers\/6$/)[0]!.body).toEqual({
      name: "Beta AG",
      email: "",
      phone: "",
      note: "",
    });
  });

  it("PATCHES the row it opened — it never creates a duplicate", async () => {
    // A POST here would split one customer's invoices, portal access and
    // tickets across two directory ids.
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Bearbeiten" }));
    await u.clear(nameBox());
    await u.type(nameBox(), "Acme SE");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/customers\/5$/)).toHaveLength(1));
    expect(sent("POST", /customers/)).toHaveLength(0);
    expect(sent("PATCH", /^\/customers\/5$/)[0]!.body).toMatchObject({ name: "Acme SE" });
  });

  it("edits the row whose button was pressed", async () => {
    const u = await open([ACME, BETA]);
    await u.click(within(row("Beta AG")).getByRole("button", { name: "Bearbeiten" }));
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/customers\/6$/)).toHaveLength(1));
    expect(sent("PATCH", /^\/customers\/5$/)).toHaveLength(0);
  });

  it("refuses to blank out the name of an existing customer", async () => {
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Bearbeiten" }));
    await u.clear(nameBox());
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Name ist erforderlich.")).toBeTruthy();
    expect(sent("PATCH", /customers/)).toHaveLength(0);
  });

  it("sends the other fields along, not just the edited one", async () => {
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Bearbeiten" }));
    await u.clear(phoneBox());
    await u.type(phoneBox(), "040 555");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/customers\/5$/)).toHaveLength(1));
    expect(sent("PATCH", /^\/customers\/5$/)[0]!.body).toEqual({
      name: "Acme GmbH",
      email: "info@acme.de",
      phone: "040 555",
      note: "Bestandskunde",
    });
  });

  it("switches from editing to a blank create form", async () => {
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Bearbeiten" }));
    await u.click(screen.getByRole("button", { name: "Abbrechen" }));
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    expect(nameBox().value).toBe("");
  });
});

describe("save failures", () => {
  it("NAMES a duplicate email instead of a generic error", async () => {
    // This is the one failure an admin can act on: the customer already
    // exists under another row.
    respond(/^\/customers$/, { error: "duplicate" }, 409, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Acme GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("E-Mail bereits vergeben.")).toBeTruthy();
  });

  it("surfaces the API's error message otherwise", async () => {
    respond(/^\/customers$/, { error: "Name zu lang" }, 422, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Fehler: Name zu lang")).toBeTruthy();
  });

  it("falls back to the status code when there is no message", async () => {
    respond(/^\/customers$/, {}, 500, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(await screen.findByText("Fehler: 500")).toBeTruthy();
  });

  it("KEEPS the form and its content when the save fails", async () => {
    respond(/^\/customers$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await screen.findByText(/Fehler/);
    expect(nameBox().value).toBe("Neu GmbH");
  });

  it("does not reload after a failed save", async () => {
    respond(/^\/customers$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await screen.findByText(/Fehler/);
    expect(sent("GET", /^\/customers$/)).toHaveLength(1);
  });

  it("does not claim a failed save succeeded", async () => {
    respond(/^\/customers$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neuer Kunde" }));
    await u.type(nameBox(), "Neu GmbH");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await screen.findByText(/Fehler/);
    expect(screen.queryByText("Gespeichert.")).toBeNull();
  });
});

describe("deleting a customer", () => {
  it("deletes the row it was asked to delete", async () => {
    const u = await open([ACME, BETA]);
    await u.click(within(row("Beta AG")).getByRole("button", { name: "Löschen" }));
    await waitFor(() => expect(sent("DELETE", /^\/customers\/6$/)).toHaveLength(1));
    expect(sent("DELETE", /^\/customers\/5$/)).toHaveLength(0);
  });

  it("reloads the directory afterwards", async () => {
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Löschen" }));
    await waitFor(() => expect(sent("GET", /^\/customers$/)).toHaveLength(2));
  });

  it("reports a refused delete instead of silently doing nothing", async () => {
    // The backend refuses to delete a customer that still has memberships or
    // invoices; the admin needs to see that, not a no-op button.
    respond(/^\/customers\/5$/, { error: "in use" }, 409, "DELETE");
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Löschen" }));
    expect(await screen.findByText("Fehler (HTTP 409).")).toBeTruthy();
  });

  it("does NOT reload after a refused delete", async () => {
    // A reload would make it look as though the row had simply vanished.
    respond(/^\/customers\/5$/, { error: "in use" }, 409, "DELETE");
    const u = await open([ACME]);
    await u.click(within(row("Acme GmbH")).getByRole("button", { name: "Löschen" }));
    await screen.findByText("Fehler (HTTP 409).");
    expect(sent("GET", /^\/customers$/)).toHaveLength(1);
  });
});
