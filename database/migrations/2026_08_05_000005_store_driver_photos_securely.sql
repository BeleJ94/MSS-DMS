ALTER TABLE drivers ADD COLUMN photo_mime VARCHAR(50) NULL AFTER photo_path;
ALTER TABLE drivers ADD COLUMN photo_data LONGBLOB NULL AFTER photo_mime;

