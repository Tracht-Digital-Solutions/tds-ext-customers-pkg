import { useEffect, useState } from "react";
import { ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

interface Customer {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  note: string | null;
}

const empty = { name: "", email: "", phone: "", note: "" };

/** Customer/company directory CRUD (list + create + inline edit + delete). */
export default function CustomersList() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [form, setForm] = useState(empty);
  const [status, setStatus] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Customer | null>(null);
  const [deleting, setDeleting] = useState(false);

  const load = async () => {
    const res = await api("/customers");
    if (res.ok) {
      setCustomers((await res.json()).customers ?? []);
    } else {
      setStatus(res.status === 401 || res.status === 403 ? "Keine Berechtigung." : `Fehler (HTTP ${res.status}).`);
    }
    setLoaded(true);
  };
  useEffect(() => {
    void load();
  }, []);

  const startNew = () => {
    setForm(empty);
    setEditing("new");
    setStatus(null);
  };
  const startEdit = (c: Customer) => {
    setForm({ name: c.name, email: c.email ?? "", phone: c.phone ?? "", note: c.note ?? "" });
    setEditing(c.id);
    setStatus(null);
  };

  const save = async () => {
    if (form.name.trim() === "") {
      setStatus("Name ist erforderlich.");
      return;
    }
    const isNew = editing === "new";
    const res = await api(isNew ? "/customers" : `/customers/${editing}`, {
      method: isNew ? "POST" : "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form),
    });
    if (res.ok) {
      setEditing(null);
      toast.success(isNew ? "Kunde angelegt." : "Kunde gespeichert.");
      void load();
    } else if (res.status === 409) {
      // A duplicate email is something to FIX in the form that is still open,
      // so it stays in-flow next to the field it is about.
      setStatus("E-Mail bereits vergeben.");
    } else {
      const d = await res.json().catch(() => ({}));
      toast.danger(`Speichern fehlgeschlagen: ${d.error ?? `HTTP ${res.status}`}`);
    }
  };

  // The customer directory backs memberships, billing and the portal, so this
  // delete cascades further than any other in the platform — it was a single
  // unguarded click.
  const confirmRemove = async () => {
    const c = pendingDelete;
    if (!c) return;
    setDeleting(true);
    try {
      const res = await api(`/customers/${c.id}`, { method: "DELETE" });
      setPendingDelete(null);
      if (res.ok) {
        toast.success(`„${c.name}" gelöscht.`);
        void load();
      } else {
        toast.danger(`Löschen fehlgeschlagen (HTTP ${res.status}).`);
      }
    } finally {
      setDeleting(false);
    }
  };

  if (!loaded) return <p><Spinner /></p>;

  return (
    <div className="tds-stack">
      {/* Validation + the load failure only — outcomes are toasts. */}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}

      {editing !== null ? (
        <div className="tds-card tds-stack">
          <h4>{editing === "new" ? "Neuer Kunde" : "Kunde bearbeiten"}</h4>
          <input className="field-boxed" type="text" placeholder="Name / Firma" aria-label="Name / Firma" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <input className="field-boxed" type="email" placeholder="E-Mail (optional)" aria-label="E-Mail" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <input className="field-boxed" type="text" placeholder="Telefon (optional)" aria-label="Telefon" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <textarea className="field-boxed" placeholder="Notiz (optional)" aria-label="Notiz" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          <div className="tds-toolbar">
            <button type="button" className="btn btn-primary" onClick={save}>Speichern</button>
            <button type="button" className="btn btn-ghost" onClick={() => setEditing(null)}>Abbrechen</button>
          </div>
        </div>
      ) : (
        <button type="button" className="btn btn-primary" onClick={startNew}>Neuer Kunde</button>
      )}

      <table className="tds-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>E-Mail</th>
            <th>Telefon</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {customers.map((c) => (
            <tr key={c.id}>
              <td>{c.name}</td>
              <td>{c.email ?? "—"}</td>
              <td>{c.phone ?? "—"}</td>
              {/* The flex belongs on a wrapper, never on the cell: `display:
                  flex` takes a cell out of the table's column algorithm, so
                  this column drifted away from its own header. `.tds-toolbar`
                  also wraps, which the bare `flex` did not. */}
              <td>
                <span className="tds-toolbar">
                  <button type="button" className="btn btn-ghost" onClick={() => startEdit(c)}>Bearbeiten</button>
                  <button type="button" className="btn btn-ghost" onClick={() => setPendingDelete(c)}>Löschen</button>
                </span>
              </td>
            </tr>
          ))}
          {customers.length === 0 ? (
            <tr>
              <td colSpan={4} className="opacity-70">Noch keine Kunden.</td>
            </tr>
          ) : null}
        </tbody>
      </table>

      <ConfirmDialog
        open={pendingDelete !== null}
        title={`Kunde „${pendingDelete?.name ?? ""}“ löschen?`}
        message="Mitgliedschaften, Projekte und Rechnungen dieses Kunden verlieren ihre Zuordnung."
        busy={deleting}
        onConfirm={() => void confirmRemove()}
        onCancel={() => setPendingDelete(null)}
      />
    </div>
  );
}
