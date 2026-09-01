<?php
// Direct status_tracker table creation
require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Create the status_tracker table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS status_tracker (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT(11) NOT NULL,
        previous_status VARCHAR(50) DEFAULT NULL,
        current_status VARCHAR(50) NOT NULL,
        reason TEXT DEFAULT NULL,
        changed_by INT(11) DEFAULT NULL,
        effective_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
        updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        KEY idx_student_id (student_id),
        KEY idx_created_at (created_at),
        CONSTRAINT fk_status_tracker_student FOREIGN KEY (student_id)
            REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_status_tracker_user FOREIGN KEY (changed_by)
            REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $conn->exec($sql);

    // Verify
    $result = $conn->query("SHOW TABLES LIKE 'status_tracker'");
    if ($result && $result->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'status_tracker table created successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Table verification failed.']);
    }

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo json_encode(['success' => true, 'message' => 'status_tracker table already exists.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
