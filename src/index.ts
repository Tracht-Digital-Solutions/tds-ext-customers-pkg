import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Customer/company directory manifest — the panel's canonical customer list,
 * backing membership editing (base user-management) and the billing/portal
 * extensions. No settings slot (no config). Admin-facing.
 */
export default defineExtension({
  id: "customers",
  name: "Firmen",
  // Kept in step with package.json/composer.json by the release workflow —
  // don't hand-edit. (It had drifted to 0.1.0 while the package was at 0.1.11,
  // because only the bump step knows the new number.)
  version: "0.1.13",
  permissions: [
    { id: "companies:read", label: "Firmen ansehen", group: "companies" },
    { id: "companies:write", label: "Firmen verwalten", group: "companies" },
  ],
  nav: [
    {
      id: "customers",
      label: "Firmen",
      href: "/firmen",
      icon: "building",
      group: "verwaltung",
      order: 15,
      permission: "companies:read",
    },
  ],
  widgets: [
    {
      id: "customers-count",
      title: "Firmen",
      island: "@tracht-digital-solutions/tds-ext-customers/widgets/Widget.astro",
      size: "sm",
      permission: "companies:read",
      dataEndpoint: "/companies/summary",
      order: 15,
    },
  ],
  routes: [
    {
      pattern: "/firmen",
      entrypoint: "@tracht-digital-solutions/tds-ext-customers/pages/Index.astro",
      permission: "companies:read",
    },
  ],
  i18n: {
    de: { "companies.title": "Firmen" },
    en: { "companies.title": "Companies" },
  },
});
