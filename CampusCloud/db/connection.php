<?php 
    $host = 'localhost';
    $user = 'root';
    $password = 'shubham';
    $db_name = 'campuscloud';
    $conn = mysqli_connect($host,$user,$password,$db_name);
    if(!$conn){
        echo 'Something went wrong!';
    }
?>