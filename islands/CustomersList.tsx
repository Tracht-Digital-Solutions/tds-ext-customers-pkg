import { useEffect, useState } from "react";
import { Spinner } from "@tracht-digital-solutions/tds-shared/components";

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
      setStatus("Gespeichert.");
      void load();
    } else {
      const d = await res.json().catch(() => ({}));
      setStatus(res.status === 409 ? "E-Mail bereits vergeben." : `Fehler: ${d.error ?? res.status}`);
    }
  };

  const remove = async (id: number) => {
    const res = await api(`/customers/${id}`, { method: "DELETE" });
    if (res.ok) void load();
    else setStatus(`Fehler (HTTP ${res.status}).`);
  };

  if (!loaded) return <p role="status"><Spinner /></p>;

  return (
    <div className="customers">
      {status ? <p className="tds-alert" role="status">{status}</p> : null}

      {editing !== null ? (
        <div className="lx-form customer-form">
          <h4>{editing === "new" ? "Neuer Kunde" : "Kunde bearbeiten"}</h4>
          <input type="text" placeholder="Name / Firma" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <input type="email" placeholder="E-Mail (optional)" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <input type="text" placeholder="Telefon (optional)" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <textarea placeholder="Notiz (optional)" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          <div className="flex gap-2">
            <button type="button" onClick={save}>Speichern</button>
            <button type="button" className="btn btn-ghost" onClick={() => setEditing(null)}>Abbrechen</button>
          </div>
        </div>
      ) : (
        <button type="button" onClick={startNew}>Neuer Kunde</button>
      )}

      <table className="lx-table">
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
              <td className="flex gap-2">
                <button type="button" className="btn btn-ghost" onClick={() => startEdit(c)}>Bearbeiten</button>
                <button type="button" className="btn btn-ghost" onClick={() => void remove(c.id)}>Löschen</button>
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
    </div>
  );
}
