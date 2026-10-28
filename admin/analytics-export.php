<?php
/**
 * OFFGRID - Admin Analytics & Reporting CSV Export Engine
 * 
 * Secure, authorization-guarded CSV generation endpoint.
 * Features:
 * - Strict require_admin() access control
 * - Server-side date range validation with prepared statements
 * - Defense against Spreadsheet / CSV Formula Injection (=, +, -, @)
 * - Strict exclusion of credentials, password hashes, and session tokens
 * - UTF-8 Byte Order Mark (BOM) for seamless Microsoft Excel & Google Sheets compatibility
 * - Indian localization (INR format, IST timestamps)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Strict Authorization Guard: Restrict access to authenticated administrators only
require_admin();

// Determine report type
$report = isset($_GET['report']) ? trim($_GET['report']) : 'bookings';
$validReports = ['bookings', 'events', 'reviews', 'monthly'];
if (!in_array($report, $validReports, true)) {
    $report = 'bookings';
}

// Date Range Filtering (optional)
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Validate date inputs if provided
$dateFilterApplied = false;
$validDateFrom = null;
$validDateTo = null;

if (!empty($dateFrom) && !empty($dateTo)) {
    $dFrom = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $dTo = DateTime::createFromFormat('Y-m-d', $dateTo);
    
    if ($dFrom && $dFrom->format('Y-m-d') === $dateFrom &&
        $dTo && $dTo->format('Y-m-d') === $dateTo) {
        if ($dateFrom <= $dateTo) {
            $validDateFrom = $dateFrom . ' 00:00:00';
            $validDateTo = $dateTo . ' 23:59:59';
            $dateFilterApplied = true;
        } else {
            // Inverted dates: swap safely
            $validDateFrom = $dateTo . ' 00:00:00';
            $validDateTo = $dateFrom . ' 23:59:59';
            $dateFilterApplied = true;
        }
    }
}

/**
 * Sanitizes a CSV cell value to prevent Spreadsheet Formula Injection (CSV Injection).
 * If the first character is an executable spreadsheet trigger (=, +, -, @, \t, \r),
 * it is prepended with a single quote (') to force text evaluation.
 *
 * @param mixed $val
 * @return string
 */
function sanitize_csv_cell($val): string {
    if ($val === null) {
        return '';
    }
    $str = (string)$val;
    if (strlen($str) > 0 && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $str = "'" . $str;
    }
    return $str;
}

/**
 * Writes an array row to the CSV handle after sanitizing each element.
 *
 * @param resource $handle
 * @param array $fields
 */
function write_csv_row($handle, array $fields): void {
    $sanitized = array_map('sanitize_csv_cell', $fields);
    fputcsv($handle, $sanitized);
}

// Generate appropriate file name
$timestampStr = date('Ymd_His');
$filename = "offgrid_{$report}_{$timestampStr}.csv";

// Configure HTTP response headers for immediate file download
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open standard PHP output stream
$out = fopen('php://output', 'w');

// Emit UTF-8 BOM so Microsoft Excel correctly renders UTF-8 encoding
fwrite($out, "\xEF\xBB\xBF");

try {
    switch ($report) {
        case 'bookings':
            // Header row
            write_csv_row($out, [
                'Booking Code',
                'Booking Date (IST)',
                'Attendee Name',
                'Attendee Email',
                'Event ID',
                'Event Title',
                'Event City',
                'Category',
                'Event Date',
                'Tickets / Seats Count',
                'Simulated Total Amount (INR)',
                'Payment Status',
                'Payment Method',
                'Payment Reference'
            ]);

            $sql = "
                SELECT 
                    b.booking_code,
                    b.booking_date,
                    u.name AS user_name,
                    u.email AS user_email,
                    e.id AS event_id,
                    e.title AS event_title,
                    e.city AS event_city,
                    e.category AS event_category,
                    e.event_date,
                    b.tickets_count,
                    b.total_amount,
                    b.payment_status,
                    b.payment_method,
                    b.payment_reference
                FROM bookings b
                JOIN users u ON b.user_id = u.id
                JOIN events e ON b.event_id = e.id
            ";

            $params = [];
            if ($dateFilterApplied) {
                $sql .= " WHERE b.booking_date >= :date_from AND b.booking_date <= :date_to";
                $params[':date_from'] = $validDateFrom;
                $params[':date_to'] = $validDateTo;
            }
            $sql .= " ORDER BY b.booking_date DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                write_csv_row($out, [
                    $row['booking_code'],
                    $row['booking_date'],
                    $row['user_name'],
                    $row['user_email'],
                    $row['event_id'],
                    $row['event_title'],
                    $row['event_city'],
                    $row['event_category'],
                    $row['event_date'],
                    (int)$row['tickets_count'],
                    number_format((float)$row['total_amount'], 2, '.', ''),
                    $row['payment_status'],
                    $row['payment_method'],
                    $row['payment_reference']
                ]);
            }
            break;

        case 'events':
            // Header row
            write_csv_row($out, [
                'Event ID',
                'Title',
                'Category',
                'City',
                'Location',
                'Event Date',
                'Event Time (IST)',
                'Price (INR)',
                'Total Capacity',
                'Available Seats',
                'Confirmed Seats Booked',
                'Occupancy Rate (%)',
                'Total Confirmed Bookings',
                'Simulated Revenue (INR)',
                'Status',
                'Created At'
            ]);

            // Query aggregated bookings safely per event to avoid Cartesian joins
            $sql = "
                SELECT 
                    e.id,
                    e.title,
                    e.category,
                    e.city,
                    e.location,
                    e.event_date,
                    e.event_time,
                    e.price,
                    e.total_capacity,
                    e.available_seats,
                    e.status,
                    e.created_at,
                    COALESCE(b_agg.confirmed_seats, 0) AS confirmed_seats,
                    COALESCE(b_agg.confirmed_bookings, 0) AS confirmed_bookings,
                    COALESCE(b_agg.simulated_revenue, 0.00) AS simulated_revenue
                FROM events e
                LEFT JOIN (
                    SELECT 
                        event_id,
                        SUM(tickets_count) AS confirmed_seats,
                        COUNT(id) AS confirmed_bookings,
                        SUM(total_amount) AS simulated_revenue
                    FROM bookings
                    WHERE payment_status = 'confirmed'
                    GROUP BY event_id
                ) b_agg ON e.id = b_agg.event_id
                ORDER BY e.event_date ASC, e.id DESC
            ";

            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cap = (int)$row['total_capacity'];
                $confSeats = (int)$row['confirmed_seats'];
                $occupancy = $cap > 0 ? round(($confSeats / $cap) * 100, 1) : 0;

                write_csv_row($out, [
                    $row['id'],
                    $row['title'],
                    $row['category'],
                    $row['city'],
                    $row['location'],
                    $row['event_date'],
                    format_event_time($row['event_time']),
                    number_format((float)$row['price'], 2, '.', ''),
                    $cap,
                    (int)$row['available_seats'],
                    $confSeats,
                    $occupancy . '%',
                    (int)$row['confirmed_bookings'],
                    number_format((float)$row['simulated_revenue'], 2, '.', ''),
                    $row['status'],
                    $row['created_at']
                ]);
            }
            break;

        case 'reviews':
            // Header row
            write_csv_row($out, [
                'Review ID',
                'Event ID',
                'Event Title',
                'Event City',
                'Reviewer Name',
                'Rating (1-5 Stars)',
                'Comment',
                'Moderation Status',
                'Moderation Note',
                'Created At (IST)'
            ]);

            $sql = "
                SELECT 
                    r.id,
                    r.rating,
                    r.comment,
                    r.status,
                    r.moderation_note,
                    r.created_at,
                    e.id AS event_id,
                    e.title AS event_title,
                    e.city AS event_city,
                    u.name AS user_name
                FROM reviews r
                JOIN events e ON r.event_id = e.id
                JOIN users u ON r.user_id = u.id
            ";

            $params = [];
            if ($dateFilterApplied) {
                $sql .= " WHERE r.created_at >= :date_from AND r.created_at <= :date_to";
                $params[':date_from'] = $validDateFrom;
                $params[':date_to'] = $validDateTo;
            }
            $sql .= " ORDER BY r.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                write_csv_row($out, [
                    $row['id'],
                    $row['event_id'],
                    $row['event_title'],
                    $row['event_city'],
                    $row['user_name'],
                    (int)$row['rating'],
                    $row['comment'],
                    $row['status'],
                    $row['moderation_note'] ?? '',
                    $row['created_at']
                ]);
            }
            break;

        case 'monthly':
            // Header row
            write_csv_row($out, [
                'Month (YYYY-MM)',
                'Total Bookings',
                'Confirmed Bookings',
                'Cancelled Bookings',
                'Confirmed Seats Booked',
                'Simulated Volume (INR)',
                'Average Booking Value (INR)'
            ]);

            $sql = "
                SELECT 
                    DATE_FORMAT(booking_date, '%Y-%m') AS month_label,
                    COUNT(id) AS total_bookings,
                    SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
                    SUM(CASE WHEN payment_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
                    SUM(CASE WHEN payment_status = 'confirmed' THEN tickets_count ELSE 0 END) AS confirmed_seats,
                    SUM(CASE WHEN payment_status = 'confirmed' THEN total_amount ELSE 0.00 END) AS simulated_revenue
                FROM bookings
                GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
                ORDER BY month_label DESC
            ";

            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $confBookings = (int)$row['confirmed_bookings'];
                $simRev = (float)$row['simulated_revenue'];
                $avgVal = $confBookings > 0 ? round($simRev / $confBookings, 2) : 0.00;

                write_csv_row($out, [
                    $row['month_label'],
                    (int)$row['total_bookings'],
                    $confBookings,
                    (int)$row['cancelled_bookings'],
                    (int)$row['confirmed_seats'],
                    number_format($simRev, 2, '.', ''),
                    number_format($avgVal, 2, '.', '')
                ]);
            }
            break;
    }

} catch (PDOException $e) {
    error_log('[OFFGRID CSV EXPORT ERROR] ' . $e->getMessage());
    write_csv_row($out, ['Error generating export: database query failed.']);
}

fclose($out);
exit;
