ALTER TABLE botsaz ADD COLUMN webhook_key VARCHAR(32) NULL;
ALTER TABLE botsaz ADD COLUMN webhook_secret VARCHAR(128) NULL;
CREATE UNIQUE INDEX uq_botsaz_webhook_key ON botsaz (webhook_key);
