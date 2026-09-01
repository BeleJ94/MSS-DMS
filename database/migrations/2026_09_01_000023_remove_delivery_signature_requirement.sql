ALTER TABLE delivery_pods
    MODIFY signature_mime VARCHAR(40) NULL,
    MODIFY signature_data MEDIUMBLOB NULL;
