ALTER TABLE login_logs
    ADD INDEX idx_login_logs_ip_action_created (ip_address, action, successful, created_at);
