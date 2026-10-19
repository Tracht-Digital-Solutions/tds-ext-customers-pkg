import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

/** Customers widget body — the directory count from `/companies/summary`. */
export default function WidgetBody() {
  return <SummaryMetric endpoint="/companies/summary" caption={() => "Firmen im Verzeichnis"} />;
}
