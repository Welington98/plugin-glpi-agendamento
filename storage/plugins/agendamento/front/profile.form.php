<?php

include('../../../inc/includes.php');

use GlpiPlugin\Agendamento\Profile;

Session::checkRight('profile', UPDATE);

if (isset($_POST['update']) && isset($_POST['profiles_id'])) {
    Session::checkCSRF($_POST);

    $profilesId = (int) $_POST['profiles_id'];
    $rights = 0;

    if (isset($_POST['rights']['read'])) {
        $rights |= READ;
    }
    if (isset($_POST['rights']['create'])) {
        $rights |= CREATE;
    }
    if (isset($_POST['rights']['update'])) {
        $rights |= UPDATE;
    }
    if (isset($_POST['rights']['delete'])) {
        $rights |= DELETE;
    }

    $profileRight = new \ProfileRight();
    $existing = $profileRight->find([
        'profiles_id' => $profilesId,
        'name' => Profile::RIGHT_NAME,
    ]);

    if (!empty($existing)) {
        $row = array_pop($existing);
        $profileRight->update([
            'id' => $row['id'],
            'rights' => $rights,
        ]);
    } else {
        $profileRight->add([
            'profiles_id' => $profilesId,
            'name' => Profile::RIGHT_NAME,
            'rights' => $rights,
        ]);
    }

    Html::back();
}

Html::displayErrorAndDie('lost');
