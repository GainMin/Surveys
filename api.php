<?php
header("Access-Control-Allow-Origin: *");

include("incs/utils.inc.php");
include("incs/dbcon.inc.php");

$api = array(
  // Получение пользователя
  "getUser"              => function($req){
    return get_user(get_credentials($req["credentials"]));
  },
  // Создание нового пользователя
  "createUser"           => function($req){
    if (
      !isset($req["credentials"]["UserName"]) ||
      !isset($req["credentials"]["UserPassword"]) ||
      !isset($req["credentials"]["FullName"])
    ) return throw_error("Некорректные входные данные");

    // 1. Проверяем существует ли пользователь
    $user = get_user($req["credentials"]);

    if ($user) return throw_error("Пользователь с таким именем уже существует");

    // 2. Создаем нового пользователя если все ок
    // P.S> Гранты уровня администратора выдаются через СУБД
    $con = get_db_con("users");
    $values = implode(", ", array_map(fn($field) => "'".$req["credentials"][$field]."'",array_keys($req["credentials"])));
    $query = "INSERT INTO users_credentials (ID, Login, Password, FullName, AccessRights) VALUES (NULL, $values, 0)";
    $results = mysqli_query($con, $query);

    if (!$results) return throw_error("Не удалось создать нового пользователя");

    return true;

  },
  // Получение всех опросов
  "getAllSurveys"        => function($req){

    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));

    if (isset($rightsCheck["error"])) return throw_error($rightsCheck["error"]);

    $surveysCon = get_db_con("surveys");
    $surveysRes = mysqli_query($surveysCon, "SELECT * FROM surveys ORDER BY IsActive, ID");

    if (!$surveysRes) return throw_error("Пока не было создано ни одного опроса");

    $surveys = array();

    while($survey = $surveysRes -> fetch_array(MYSQLI_ASSOC)){

      $questionsRes = mysqli_query($surveysCon, "SELECT * FROM questions_$survey[ID] ORDER BY AskOrder, ID");
      $questions = array();

      if ($questionsRes){
        while($question = $questionsRes -> fetch_array(MYSQLI_ASSOC)){
          array_push($questions, $question);
        }
      }

      $survey += array("Questions" => $questions);
      array_push($surveys, $survey);

    }

    return $surveys;

  },
  // Получение активного опроса
  "getActiveSurvey"      => function($req){
    $surveysCon = get_db_con("surveys");
    $surveysRes = mysqli_query($surveysCon, "SELECT * FROM surveys WHERE IsActive=1");

    if (!$surveysRes) return throw_error("Активных опросов нет");

    $surveys = array();

    while($survey = $surveysRes -> fetch_array(MYSQLI_ASSOC)){

      $questionsRes = mysqli_query($surveysCon, "SELECT * FROM questions_$survey[ID] ORDER BY AskOrder, ID");
      $questions = array();

      while($question = $questionsRes -> fetch_array(MYSQLI_ASSOC)){
        array_push($questions, $question);
      }

      array_push($surveys, array(
        "data"      => $survey,
        "questions" => $questions
      ));

    }

    return $surveys;

  },
  // Получение опроса по ID
  "getSurveyByID"        => function($req){

    if (!isset($req["data"]["ID"])) return throw_error("Опрос не найден");

    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));

    $surveysCon = get_db_con("surveys");
    $surveyRes = mysqli_query($surveysCon, "SELECT * FROM results_".$req["data"]["ID"]." WHERE AuthorLogin=".$req["credentials"]["UserName"]);

    if ($surveyRes && $rightsCheck !== true) return throw_error("Вы не можете пройти опрос повторно");

    $surveysCon = get_db_con("surveys");
    $surveysRes = mysqli_query($surveysCon, "SELECT * FROM surveys WHERE ID=$req[data][ID]");

    if (!$surveysRes) return throw_error("Опрос не найден");

    $surveys = array();

    while($survey = $surveysRes -> fetch_array(MYSQLI_ASSOC)){

      $questionsRes = mysqli_query($surveysCon, "SELECT * FROM questions_$survey[ID] WHERE ID=$survey[ID] ORDER BY AskOrder, ID");
      $questions = array();

      while($question = $questionsRes -> fetch_array(MYSQLI_ASSOC)){
        array_push($questions, $question);
      }

      array_push($surveys, array(
        "data"      => $survey,
        "questions" => $questions
      ));

    }

    return $surveys;
  },
  // Создание/настройка опроса
  "setSurvey"            => function($req){
    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));
    if (isset($rightsCheck["error"])) return throw_error($rightsCheck["error"]);

    if (
      !isset($req["data"]["ID"]) &&
      (
        !isset($req["data"]["Title"]) ||
        !isset($req["data"]["Description"]) ||
        !isset($req["data"]["IsActive"])
      )
    ) return throw_error("Некорректные входные данные");

    $con = get_db_con("surveys");

    if ($req["data"]["IsActive"] === "1"){
      $res = mysqli_query($con, "UPDATE surveys SET IsActive=0");
      if (!$res) return throw_error("Возникла ошибка при создании/обновлении опроса. [Код: 1]");
    }

    // 1. Создаем запись об опросе в таблице surveys и получаем A.I. идентификатор для создания таблиц либо берем готовый
    $surveyID = isset($req["data"]["ID"]) ? $req["data"]["ID"] : get_last_inserted_id($con, mysqli_query($con, "INSERT INTO surveys (ID) VALUES (NULL)"));

    // 2. Генерация query для апдейта последнего добавленного итема/итема на который ссылается идентификатор в теле запроса
    $workFields = array_filter(array_keys($req["data"]), fn($key) => $key !== "ID");
    $query = "UPDATE surveys SET ".implode(",", array_map(fn($field) => $field."='".$req["data"][$field]."'", $workFields))." WHERE ID=$surveyID";

    // 3. Апдейт
    $results = mysqli_query($con, $query);
    if (!$results) return throw_error("Возникла ошибка при создании/обновлении опроса. [Код: 2]");

    if (!isset($req["data"]["ID"])){
      // 4. Создание таблицы для вопросов (если не создана)
      $results = mysqli_query($con, "CREATE TABLE questions_$surveyID LIKE surveys_templates.questions_template");
      if (!$results) return throw_error("Возникла ошибка при создании/обновлении опроса. [Код: 3]");
      // 5. Создание таблицы для ответов (если не создана)
      $results = mysqli_query($con, "CREATE TABLE results_$surveyID LIKE surveys_templates.results_template");
      if (!$results) return throw_error("Возникла ошибка при создании/обновлении опроса. [Код: 4]");
    }

    // 6. Возврат опроса
    return array("ID" => $surveyID);

  },
  // Удаление опроса
  "deleteSurvey"         => function($req){
    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));

    if (isset($rightsCheck["error"])) return throw_error($rightsCheck["error"]);

    if (
      !isset($req["data"]["ID"])
    ) return throw_error("Некорректные входные данные");

    $con = get_db_con("surveys");

    // 1. Удаляем запись об опросе
    $results = mysqli_query($con, "DELETE FROM surveys WHERE ID=".$req["data"]["ID"]);
    if (!$results) return throw_error("Возникла ошибка при удалении опроса. [Код: 1]");

    // 2. Удаляем таблицу с вопросами
    $results = mysqli_query($con, "DROP TABLE questions_".$req["data"]["ID"]);
    if (!$results) return throw_error("Возникла ошибка при удалении опроса. [Код: 2]");

    // 3. Удаляем таблицу с ответами
    $results = mysqli_query($con, "DROP TABLE results_".$req["data"]["ID"]);
    if (!$results) return throw_error("Возникла ошибка при удалении опроса. [Код: 3]");

    return true;
  },
  // Создание нового вопроса в опросе или изменение существующего
  "setSurveyQuestion"    => function($req){

    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));

    if (isset($rightsCheck["error"])) return throw_error($rightsCheck["error"]);

    if (
      !isset($req["data"]["ID"]) ||
      !isset($req["data"]["question"])
    ) return throw_error("Некорректные данные");

    // 1. Проверка существования опроса
    $con = get_db_con("surveys");
    $results = mysqli_query($con, "SELECT ID FROM surveys WHERE ID=".$req["data"]["ID"]);

    if (!$results) return throw_error("Такого опроса не существует");

    // 2. Загружаем из таблицы все вопросы для определения их порядкового номера
    $results = mysqli_query($con, "SELECT * FROM questions_".$req["data"]["ID"]." ORDER BY AskOrder DESC");
    $questions = array();

    if($results){
      while($question = $results -> fetch_array(MYSQLI_ASSOC)){
        if (!isset($req["data"]["question"]["ID"]) || $req["data"]["question"]["ID"] !== $question["ID"]){
          array_push($questions, $question);
        }
      }
    }

    // 3. Инициализируем запись для нового вопроса
    $workFields = array_filter(array_keys($req["data"]["question"]), fn($key) => $key !== "ID");
    if (count($questions) === 0){
      $req["data"]["question"]["AskOrder"] = 0;
    } else if (isset($req["data"]["question"]["AskOrder"]) && $req["data"]["question"]["AskOrder"] > $questions[0]["AskOrder"]){
      $req["data"]["question"]["AskOrder"] = $questions[0]["AskOrder"] + 1;
    }

    // 4. Апдейтим порядковый номер для вопросов, чей индекс больше или равен индексу текущего вопроса если нужно (НЕ в таблице):
    for ($i = 0; $i < count($questions); $i++){
      $question = $questions[$i];
      if ($question["AskOrder"] >= $req["data"]["question"]["AskOrder"]){
        $questions[$i]["AskOrder"] = $question["AskOrder"] + 1;
      }
    }

    // 5. Добавляем в массив вопросов новый вопрос для назначения корректного порядкового номера
    array_push($questions, $req["data"]["question"]);

    // 6. Сортируем массив вопросов по возрастанию
    usort($questions, fn($a, $b) => $a["AskOrder"] == $b["AskOrder"] ? 0 : ($a["AskOrder"] < $b["AskOrder"] ? -1 : 1));

    // 7. Пушим в таблицу обновленные индексы вопросов
    for ($i = 0; $i < count($questions); $i++){
      $question = $questions[$i];
        if (isset($question["ID"])){
          $query = "UPDATE questions_".$req["data"]["ID"]." SET AskOrder=".$i." WHERE ID=".$question["ID"];
          $results = mysqli_query($con, $query);
        }
    }

    // 8. Пушим в таблицу новый вопрос (или апдейтим существующий целевой)
    if (isset($req["data"]["question"]["ID"])){
      $query = "UPDATE questions_".$req["data"]["ID"]." SET ".implode(", ", array_map(fn($field) => $field."='".$req["data"]["question"][$field]."'", $workFields))." WHERE ID=".$req["data"]["question"]["ID"];
    } else {
      $query = "INSERT INTO questions_".$req["data"]["ID"]."(ID, ".implode(", ", $workFields).") VALUES (NULL, ".implode(", ", array_map(fn($field) => "'".$req["data"]["question"][$field]."'", $workFields)).")";
    }

    $results = mysqli_query($con, $query);

    if (!$results) return throw_error("Не удалось обработать вопрос");

    return true;

  },
  // Удаление вопроса в опросе
  "deleteSurveyQuestion" => function($req){
    $rightsCheck = check_admin_rights(get_credentials($req["credentials"]));

    if (isset($rightsCheck["error"])) return throw_error($rightsCheck["error"]);

    if (
      !isset($req["data"]["ID"]) ||
      !isset($req["data"]["question"]["ID"])
    ) return throw_error("Некорректные данные");

    // 1. Проверка существования опроса
    $con = get_db_con("surveys");
    $results = mysqli_query($con, "SELECT ID FROM surveys WHERE ID=".$req["data"]["ID"]);

    if (!$results) return throw_error("Такого опроса не существует");

    // 2. Удаляем вопрос
    $results = mysqli_query($con, "DELETE FROM questions_".$req["data"]["ID"]." WHERE ID=".$req["data"]["question"]["ID"]);

    if (!$results) return throw_error("Не удалось удалить вопрос");

    // 3. Загружаем из таблицы все вопросы для определения их порядкового номера
    $results = mysqli_query($con, "SELECT * FROM questions_".$req["data"]["ID"]." ORDER BY AskOrder DESC");
    $questions = array();

    if($results){
      while($question = $results -> fetch_array(MYSQLI_ASSOC)){
        array_push($questions, $question);
      }
    }

    // 4. Сортируем массив вопросов по возрастанию
    usort($questions, fn($a, $b) => $a["AskOrder"] == $b["AskOrder"] ? 0 : ($a["AskOrder"] < $b["AskOrder"] ? -1 : 1));

    // 5. Пушим в таблицу обновленные индексы вопросов
    for ($i = 0; $i < count($questions); $i++){
      $question = $questions[$i];
        if (isset($question["ID"])){
          $query = "UPDATE questions_".$req["data"]["ID"]." SET AskOrder=".$i." WHERE ID=".$question["ID"];
          $results = mysqli_query($con, $query);
        }
    }

    return true;
  },
  // Получение ответов пользователей
  "getSurveysResults"    => function($req){

    if (!isset($req["data"]["ID"])) return throw_error("Некорректные входные данные");

    $surveysCon = get_db_con("surveys");

    $surveyRes = mysqli_query($surveysCon, "SELECT * FROM results_".$req["data"]["ID"]);
    $surveyAnswers = array();

    if ($surveyRes) {
      while($surveyAnswer = $surveyRes -> fetch_array(MYSQLI_ASSOC)){
        array_push($surveyAnswers, $surveyAnswer);
      }
    }

    return $surveyAnswers;
  },
  // Создание нового опроса или изменение существующего
  "setResult"            => function($req){
    if (
      !isset($req["data"]["ID"]) ||
      !isset($req["data"]["Answer"])
    ) return throw_error("Некорректные входные данные");

    $surveysCon = get_db_con("surveys");
    $query = "INSERT INTO results_".$req["data"]["ID"]." (ID, AuthorLogin, Answers) VALUES (NULL, '".$req["credentials"]["UserName"]."', ".json_encode($req["data"]["Answer"], JSON_UNESCAPED_UNICODE).")";

    $results = mysqli_query($surveysCon, $query);

    if (!$results) return throw_error("Не удалось сохранить ответ пользователя");

    return true;
  },
  // Удаление ответов пользователя в опросе
  "deleteResult"         => function($req){
    if (
      !isset($req["data"]["ID"]) ||
      !isset($req["data"]["ResID"])
    ) return throw_error("Некорректные входные данные");

    $surveysCon = get_db_con("surveys");
    $query = "DELETE FROM results_".$req["data"]["ID"]." WHERE ID=".$req["data"]["ResID"];
    $results = mysqli_query($surveysCon, $query);

    if (!$results) return throw_error("Не удалось удалить ответ пользователя");

    return true;
  },

);

$post = json_decode(file_get_contents("php://input"), JSON_UNESCAPED_UNICODE);

if (isset($post["method"])){
  echo json_encode(
    isset($api[$post["method"]]) ? $api[$post["method"]]($post) : throw_error("Некорректно указан метод"),
    JSON_UNESCAPED_UNICODE
  );
}

if ($_SERVER["REQUEST_METHOD"] === "GET"){
  echo "GET запросы не обрабатываются этим API";
}


?>
