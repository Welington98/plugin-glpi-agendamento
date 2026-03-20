<?php

namespace GlpiPlugin\Agendamento;

use CommonDBTM;
use CommonGLPI;
use Html;
use ProfileRight;
use Session;

class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    const RIGHT_NAME = 'plugin_agendamento';

    public static function getTypeName($nb = 0)
    {
        return __('Agendamento', 'agendamento');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            $item instanceof \Profile
            && $item->getField('id')
        ) {
            return self::createTabEntry(__('Agendamento', 'agendamento'), 0, null, 'ti ti-calendar-event');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            $profile = new self();
            $profile->showProfileForm($item->getID());
        }
        return true;
    }

    public function showProfileForm(int $profilesId): void
    {
        $canEdit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        $profileRight = new ProfileRight();
        $existing = $profileRight->find([
            'profiles_id' => $profilesId,
            'name' => self::RIGHT_NAME,
        ]);
        $currentRights = !empty($existing) ? (int) array_pop($existing)['rights'] : 0;

        echo "<div class='spaced'>";

        if ($canEdit) {
            echo "<form method='post' action='" . htmlspecialchars(\Plugin::getWebDir('agendamento')) . "/front/profile.form.php'>";
            echo Html::hidden('profiles_id', ['value' => $profilesId]);
        }

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='6'><i class='ti ti-calendar-event me-1'></i>" . __('Permissões do Plugin Agendamento', 'agendamento') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Visualizar agenda geral', 'agendamento') . "</td>";
        echo "<td>";
        Html::showCheckbox([
            'name' => 'rights[read]',
            'checked' => (bool) ($currentRights & READ),
        ]);
        echo "</td>";

        echo "<td>" . __('Criar agendamentos', 'agendamento') . "</td>";
        echo "<td>";
        Html::showCheckbox([
            'name' => 'rights[create]',
            'checked' => (bool) ($currentRights & CREATE),
        ]);
        echo "</td>";

        echo "<td>" . __('Editar/Alterar status', 'agendamento') . "</td>";
        echo "<td>";
        Html::showCheckbox([
            'name' => 'rights[update]',
            'checked' => (bool) ($currentRights & UPDATE),
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Excluir agendamentos', 'agendamento') . "</td>";
        echo "<td>";
        Html::showCheckbox([
            'name' => 'rights[delete]',
            'checked' => (bool) ($currentRights & DELETE),
        ]);
        echo "</td>";
        echo "<td colspan='4'></td>";
        echo "</tr>";

        if ($canEdit) {
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center' colspan='6'>";
            echo "<input type='submit' name='update' class='btn btn-primary' value='" . _sx('button', 'Save') . "'>";
            echo "</td></tr>";
        }

        echo "</table>";

        if ($canEdit) {
            Html::closeForm();
        }
        echo "</div>";
    }

    public static function installRights(): void
    {
        $profileRight = new ProfileRight();
        $profile = new \Profile();
        $profiles = $profile->find();

        foreach ($profiles as $profileData) {
            $existing = $profileRight->find([
                'profiles_id' => $profileData['id'],
                'name' => self::RIGHT_NAME,
            ]);

            if (!empty($existing)) {
                continue;
            }

            $rights = 0;
            switch ($profileData['name']) {
                case 'Super-Admin':
                    $rights = READ | CREATE | UPDATE | DELETE | PURGE;
                    break;
                case 'Admin':
                    $rights = READ | CREATE | UPDATE;
                    break;
                case 'Technician':
                    $rights = READ;
                    break;
            }

            $profileRight->add([
                'profiles_id' => $profileData['id'],
                'name' => self::RIGHT_NAME,
                'rights' => $rights,
            ]);
        }
    }

    public static function uninstallRights(): void
    {
        $profileRight = new ProfileRight();
        $profileRight->deleteByCriteria(['name' => self::RIGHT_NAME]);
    }
}
