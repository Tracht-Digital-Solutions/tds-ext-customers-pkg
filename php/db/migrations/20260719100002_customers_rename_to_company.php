<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `customer` → `company`.
 *
 * This table always held a **Firma** — name, email, phone, note. The people are
 * `app_user` rows in tds-auth-api, and always were. Calling this one "customer"
 * is what made the product look like it had two kinds of people — see the
 * matching rename in tds-auth-api (20260814000001).
 *
 * A rename, not a copy: the ids stay, which is what keeps
 * `tds_auth.app_user_company.company_id` pointing at the same companies. That
 * pointer crosses databases and carries no foreign key, so an id change here
 * would silently detach every portal user from their company with nothing to
 * catch it.
 *
 * The unique index keeps its old name (`uq_customer_email`). Index names are
 * internal, and dropping + re-adding a UNIQUE index to relabel it opens a
 * window with no uniqueness for pure cosmetics.
 *
 * Stays in this module's `20260719100*` version band — every composed module
 * shares one `phinxlog`, so both the class name and the numeric prefix have to
 * be unique across all of them.
 */
final class CustomersRenameToCompany extends AbstractMigration
{
    public function change(): void
    {
        $this->table('customer')->rename('company')->update();
    }
}
