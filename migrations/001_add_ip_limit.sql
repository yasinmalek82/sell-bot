-- Legacy one-off migration. Current installs/upgrades add this field through table.php.
-- Run only if table.php cannot be executed on an older installation.

ALTER TABLE product
  ADD COLUMN ip_limit INT NOT NULL DEFAULT 0;

-- If your DB user requires privileges or you use a migration tool, adapt accordingly.
