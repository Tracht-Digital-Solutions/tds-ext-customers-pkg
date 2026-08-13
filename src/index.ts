import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Customer/company directory manifest — the panel's canonical customer list,
 * backing membership editing (base user-management) and the billing/portal
 * extensions. No settings slot (no config). Admin-facing.
 */
export default defineExtension({
  id: "customers",
  name: "Kunden",
  // Kept in step with package.json/composer.json by the release workflow —
  // don't hand-edit. (It had drifted to 0.1.0 while the package was at 0.1.11,
  // because only the bump step knows the new number.)
  version: "0.1.11",
  permissions: [
    { id: "customers:read", label: "Kunden ansehen", group: "customers" },
    { id: "customers:write", label: "Kunden verwalten", group: "customers" },
  ],
  nav: [
    {
      id: "customers",
      label: "Kunden",
      href: "/customers",
      icon: "users",
      group: "verwaltung",
      order: 15,
      permission: "customers:read",
    },
  ],
  widgets: [
    {
      id: "customers-count",
      title: "Kunden",
      island: "@tracht-digital-solutions/tds-ext-customers/widgets/Widget.astro",
      size: "sm",
      permission: "customers:read",
      dataEndpoint: "/customers/summary",
      order: 15,
    },
  ],
  routes: [
    {
      pattern: "/customers",
      entrypoint: "@tracht-digital-solutions/tds-ext-customers/pages/Index.astro",
      permission: "customers:read",
    },
  ],
  i18n: {
    de: { "customers.title": "Kunden" },
    en: { "customers.title": "Customers" },
  },
});
