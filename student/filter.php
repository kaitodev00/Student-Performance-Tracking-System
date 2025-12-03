<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include '../config/db.php';

// Start the session to retrieve student_id
session_start();

// Fetch student_id from the session (ensure that the user is logged in)
$student_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if (!$student_id) {
    echo "Student is not logged in.";
    exit;
}

// Sanitize the user input for combined filter (year level and semester)
$selectedFilter = isset($_POST['filter']) ? mysqli_real_escape_string($conn, $_POST['filter']) : '1-1'; // Default to First Year, First Semester

// Split the combined filter into year level and semester
list($selectedYearLevel, $selectedSemester) = explode('-', $selectedFilter);

// Fetch the filter options for Year Level and Semester
$filterOptions = [
    '1-1' => 'First Year, First Semester',
    '2-1' => 'Second Year, First Semester',
    '3-1' => 'Third Year, First Semester',
    '4-1' => 'Fourth Year, First Semester',
];

// Fetch courses by year level and semester
$queryCourses = "SELECT c.courseID, c.courseCode, c.courseDesc, c.yearlevel_id, c.semester_id 
                 FROM courses c 
                 WHERE c.yearlevel_id = '$selectedYearLevel' AND c.semester_id = '$selectedSemester'";

// Execute the courses query
$coursesResult = $conn->query($queryCourses);

// Create the table for courses
$output = '';
if ($coursesResult->num_rows > 0) {
    $output .= '<h4>Courses for ' . $filterOptions[$selectedFilter] . '</h4>';
    $output .= '<table>';
    $output .= '<thead>
                    <tr>
                        <th>Course Code</th>
                        <th>Course Description</th>
                        <th>Midterm Grade</th>
                        <th>Final Grade</th>
                        <th>Final Grade</th>
                    </tr>
                </thead>';
    $output .= '<tbody>';
    while ($row = $coursesResult->fetch_assoc()) {
        // Check if grades are posted for this course
        $courseID = $row['courseID'];
        $studentQuery = "
            SELECT g.midterm, g.final, g.finalgrade
            FROM tblstudentgrade g
            WHERE g.course_id = '$courseID' AND g.student_id = '$student_id'";

        $gradesResult = $conn->query($studentQuery);
        $grades = $gradesResult->fetch_assoc();

        $output .= '<tr>';
        $output .= '<td>' . htmlspecialchars($row['courseCode']) . '</td>';
        $output .= '<td>' . htmlspecialchars($row['courseDesc']) . '</td>';
        $output .= '<td>' . ($grades ? htmlspecialchars($grades['midterm']) : '<span class="no-grades">Not Posted</span>') . '</td>';
        $output .= '<td>' . ($grades ? htmlspecialchars($grades['final']) : '<span class="no-grades">Not Posted</span>') . '</td>';
        $output .= '<td>' . ($grades ? htmlspecialchars($grades['finalgrade']) : '<span class="no-grades">Not Posted</span>') . '</td>';
        $output .= '</tr>';
    }
    $output .= '</tbody>';
    $output .= '</table>';
} else {
    $output .= '<p>No courses available for the selected filters.</p>';
}

// Return the output as response to the AJAX request
echo $output;
?>
