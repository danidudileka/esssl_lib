<?php
// File: admin/includes/sidebar_data.php

function getSidebarCounts($db) {
    try {
        // Get active reservations count
        $reservations_stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM book_loans 
            WHERE status IN ('active', 'overdue', 'returned', 'returned_damaged')
        ");
        $reservations_count = $reservations_stmt->fetch()['count'] ?? 0;
        
        // Get pending approvals count
        $pending_stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM book_loans 
            WHERE approval_status = 'pending'
        ");
        $pending_count = $pending_stmt->fetch()['count'] ?? 0;
        
        // Get unread messages count (if you want to add this later)
        $messages_stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM admin_messages 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $messages_count = $messages_stmt->fetch()['count'] ?? 0;
        
        return [
            'reservations' => $reservations_count,
            'pending' => $pending_count,
            'messages' => $messages_count
        ];
        
    } catch (Exception $e) {
        return [
            'reservations' => 0,
            'pending' => 0,
            'messages' => 0
        ];
    }
}
?>