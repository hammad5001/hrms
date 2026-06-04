<?php
require_once 'config.php';

$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

if (!$employee_id) {
    echo '<p style="text-align: center; padding: 30px;">Invalid employee ID</p>';
    exit;
}

// Get employee details
$emp = $conn->query("SELECT full_name FROM employees WHERE id = $employee_id");
if (!$emp || $emp->num_rows == 0) {
    echo '<p style="text-align: center; padding: 30px;">Employee not found</p>';
    exit;
}
$employee = $emp->fetch_assoc();

// Get status history
$history = $conn->query("
    SELECT * FROM employee_status_log 
    WHERE employee_id = $employee_id 
    ORDER BY created_at DESC
");

if (!$history || $history->num_rows == 0) {
    echo '<p style="text-align: center; padding: 30px;">No status history found</p>';
    exit;
}
?>
<div style="margin-bottom: 20px;">
    <h3 style="color: var(--gray-900);"><?php echo htmlspecialchars($employee['full_name'] ?: 'Unknown'); ?></h3>
</div>

<?php while($row = $history->fetch_assoc()): ?>
    <div style="padding: 15px; border-bottom: 1px solid var(--gray-200);">
        <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 8px; background: <?php 
            echo $row['status'] == 'active' ? '#d1fae5' : 
                ($row['status'] == 'vacation' ? '#fef3c7' : '#fee2e2'); 
        ?>; color: <?php 
            echo $row['status'] == 'active' ? '#059669' : 
                ($row['status'] == 'vacation' ? '#d97706' : '#dc2626'); 
        ?>;">
            <?php echo ucfirst($row['status']); ?>
        </span>
        <div style="font-size: 12px; color: var(--gray-500); margin-bottom: 5px;">
            <i class="fas fa-calendar-alt"></i> 
            <?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?>
        </div>
        <?php if (!empty($row['remarks'])): ?>
            <div style="color: var(--gray-700); font-size: 14px;">
                <i class="fas fa-comment"></i> <?php echo htmlspecialchars($row['remarks']); ?>
            </div>
        <?php endif; ?>
    </div>
<?php endwhile; ?>