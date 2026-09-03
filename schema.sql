-- ==========================================
-- SQL SCRIPT: qpaypro_ai_logs table creation (SQLite)
-- ==========================================

-- Tabla actualizada para la versión conversacional
CREATE TABLE IF NOT EXISTS qpaypro_ai_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mensaje_usuario TEXT NOT NULL,
    respuesta_ia TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
