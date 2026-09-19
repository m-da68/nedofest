<?php
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    $db = mysqli_connect("localhost", "nedofest", "Ci2mxPG*T36OcczW", "nedofest", "3306");
    if (!mysqli_select_db($db, "nedofest")){
        mysqli_close($db);
        die("DB not found");
    }

    switch ($_GET["method"]) {
        case "tmpuser":
            if (!isset($_POST["email"])) {
                break;
            }
            date_default_timezone_set('Europe/Moscow');
            $datetime = date('d.m.Y H:i:s');
            $name = $_POST["name"];
            $email = $_POST["email"];
            mysqli_query($db, "INSERT INTO `tmpuser`(`name`, `email`, `datetime`, `payed`) VALUES ('$name','$email','$datetime', '0')");
            header("Location: https://yookassa.ru/my/i/aq6tjs9etntQ/l");
            break;
    }

?>