<?php

function get_last_inserted_id($con, $res){
  return mysqli_insert_id($con);
}

function throw_error($text){
  return array("error" => $text);
}

function get_credentials($req){
  $credentials = array(
    "UserName"     => null,
    "UserPassword" => null,
    "AuthToken"    => null
  );

  if (isset($req["UserName"])) $credentials["UserName"] = $req["UserName"];
  if (isset($req["UserPassword"])) $credentials["UserPassword"] = $req["UserPassword"];
  if (isset($req["AuthToken"])) $credentials["AuthToken"] = $req["AuthToken"];

  return $credentials;
}

function get_user($credentials){
  if (
    !$credentials["UserName"] ||
    (
      !$credentials["AuthToken"] &&
      !$credentials["UserPassword"]
    )
  ) return throw_error("Некорректные данные пользователя");

  $con = get_db_con("users");

  $result = mysqli_query($con, "SELECT ID, UserName, FullName, AuthToken, AccessRights FROM users_credentials WHERE UserName='$credentials[UserName]' AND UserPassword='$credentials[UserPassword]' OR AuthToken='$credentials[AuthToken]'");

  if (!$result) return throw_error("Пользователь не найден");

  while($res = $result -> fetch_array(MYSQLI_ASSOC)){
    return $res;
  }

}

function check_admin_rights($credentials){
  $user = get_user($credentials);

  if (
    isset($user["error"]) ||
    $user["AccessRights"] === "0"
  ) return throw_error("У Вас недостаточно прав для выполнения этой операции");

  return true;
}

?>
