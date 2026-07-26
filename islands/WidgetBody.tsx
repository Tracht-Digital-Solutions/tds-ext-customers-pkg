import { useEffect, useState } from "react";

/** Customers widget body — the directory count. Same-origin fetch with credentials. */
export default function WidgetBody() {
  const [count, setCount] = useState<number | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    fetch("/customers/summary", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((d: { count: number }) => setCount(d.count))
      .catch(() => setError(true));
  }, []);

  if (error) return <p className="tds-widget__metric">—</p>;
  if (count === null) return <p className="tds-widget__metric">…</p>;

  return (
    <div className="widget__body">
      <p className="tds-widget__metric">{count}</p>
      <p className="widget__label">Kunden im Verzeichnis</p>
    </div>
  );
}
