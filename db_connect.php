<?php
$host     = "localhost";
$username = "root";
$password = ""; // Add your MySQL password if set
$database = "grievance_db";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
?>