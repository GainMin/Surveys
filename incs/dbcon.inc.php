<?php


function get_db_con($dbname, $username = "root", $userpass = "B]mo*jKNx!xcpIHC"){
  $con = mysqli_connect("localhost", $username, $userpass, $dbname);
  mysqli_set_charset($con, "utf8mb4");

  if ($con == false){
    echo "Ошибка подключения к базе данных. Обратитесь к системному администратору";
    return false;
  }
  return $con;
}

?>
