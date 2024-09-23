<?php
/*
Plugin Name: VK Auto Poster
Description: Автоматически публикует и обновляет записи WordPress в группе ВКонтакте.
Version: 5
Author: Tech communist
*/

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

// Добавляем настройки плагина в меню админки
add_action('admin_menu', 'vk_plugin_create_menu');

function vk_plugin_create_menu() {
    add_options_page(
        'VK Auto Poster Settings',
        'VK Auto Poster',
        'manage_options',
        'vk-auto-poster',
        'vk_plugin_settings_page'
    );
}

// Страница настроек плагина
function vk_plugin_settings_page() {
    ?>
    <div class="wrap">
        <h1>Настройки VK Auto Poster</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('vk-plugin-settings-group');
            do_settings_sections('vk-plugin-settings-group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Access Token</th>
                    <td><input type="text" name="vk_access_token" value="<?php echo esc_attr(get_option('vk_access_token')); ?>" size="50"/></td>
                </tr>
                <tr valign="top">
                    <th scope="row">ID Группы</th>
                    <td><input type="text" name="vk_group_id" value="<?php echo esc_attr(get_option('vk_group_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Максимальное количество символов</th>
                    <td><input type="number" name="vk_max_length" value="<?php echo esc_attr(get_option('vk_max_length', 300)); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Отправлять изображения</th>
                    <td><input type="checkbox" name="vk_send_image" value="1" <?php checked(1, get_option('vk_send_image', 1)); ?> /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Отправлять теги</th>
                    <td><input type="checkbox" name="vk_send_tags" value="1" <?php checked(1, get_option('vk_send_tags', 1)); ?> /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Отправлять ссылку на запись</th>
                    <td><input type="checkbox" name="vk_send_link" value="1" <?php checked(1, get_option('vk_send_link', 1)); ?> /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Регистрируем настройки плагина
add_action('admin_init', 'vk_plugin_register_settings');

function vk_plugin_register_settings() {
    register_setting('vk-plugin-settings-group', 'vk_access_token');
    register_setting('vk-plugin-settings-group', 'vk_group_id');
    register_setting('vk-plugin-settings-group', 'vk_max_length');
    register_setting('vk-plugin-settings-group', 'vk_send_image');
    register_setting('vk-plugin-settings-group', 'vk_send_tags');
    register_setting('vk-plugin-settings-group', 'vk_send_link');
}

// Добавляем чекбокс на страницу редактирования записи
add_action('add_meta_boxes', 'vk_add_meta_box');

function vk_add_meta_box() {
    add_meta_box(
        'vk_meta_box',
        'VK Auto Poster',
        'vk_meta_box_callback',
        'post',
        'side'
    );
}

function vk_meta_box_callback($post) {
    wp_nonce_field('vk_meta_box', 'vk_meta_box_nonce');
    $value = get_post_meta($post->ID, '_vk_send_to_vk', true);
    $checked = ($value !== 'no') ? 'checked' : '';
    echo '<label for="vk_send_to_vk">';
    echo '<input type="checkbox" id="vk_send_to_vk" name="vk_send_to_vk" value="yes" ' . $checked . ' />';
    echo ' Отправить запись в ВКонтакте</label>';
}

// Сохраняем данные при сохранении поста
add_action('save_post', 'vk_save_post', 10, 3);

function vk_save_post($post_id, $post, $update) {
    // Проверяем nonce
    if (!isset($_POST['vk_meta_box_nonce']) || !wp_verify_nonce($_POST['vk_meta_box_nonce'], 'vk_meta_box')) {
        return;
    }

    // Избегаем автосохранений и ревизий
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    // Проверяем тип записи
    if ($post->post_type != 'post') {
        return;
    }

    // Проверяем статус публикации
    if ($post->post_status != 'publish') {
        return;
    }

    // Сохраняем значение чекбокса
    $send_to_vk = isset($_POST['vk_send_to_vk']) ? 'yes' : 'no';
    update_post_meta($post_id, '_vk_send_to_vk', $send_to_vk);

    // Если чекбокс не установлен, не отправляем в ВК
    if ($send_to_vk != 'yes') {
        return;
    }

    // Получаем токен и ID группы из настроек
    $access_token = get_option('vk_access_token');
    $group_id = get_option('vk_group_id');

    if (!$access_token || !$group_id) {
        return;
    }

    // Формируем данные для отправки
    $max_length = get_option('vk_max_length', 300);

    $post_title = get_the_title($post_id);
    $post_content = strip_tags($post->post_content);
    $post_excerpt = mb_substr($post_content, 0, $max_length);
    $post_url = get_permalink($post_id);

    $message = $post_title . "\n\n" . $post_excerpt;

    // Добавляем ссылку на запись, если включено в настройках
    if (get_option('vk_send_link', 1)) {
        $message .= "\n\n" . $post_url;
    }

    // Добавляем теги, если включено в настройках
    if (get_option('vk_send_tags', 1)) {
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        if (!empty($tags)) {
            $message .= "\n\n#" . implode(' #', $tags);
        }
    }

    // Работа с изображениями
    $attachments = [];

    if (get_option('vk_send_image', 1)) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');

            // Загружаем изображение на сервер ВК
            $upload_server = vk_get_upload_server($access_token, $group_id);
            if ($upload_server) {
                $photo = vk_upload_photo($access_token, $group_id, $upload_server, $image_url);
                if ($photo) {
                    $attachments[] = 'photo' . $photo['owner_id'] . '_' . $photo['id'];
                }
            }
        }
    }

    // Если это обновление записи
    $vk_post_id = get_post_meta($post_id, '_vk_post_id', true);

    if ($vk_post_id && $update) {
        // Обновляем пост в ВК
        vk_edit_post($access_token, $group_id, $vk_post_id, $message, $attachments);
    } else {
        // Публикуем новый пост в ВК
        $vk_post_id = vk_publish_post($access_token, $group_id, $message, $attachments);
        if ($vk_post_id) {
            // Сохраняем ID поста ВК в мета-данных
            update_post_meta($post_id, '_vk_post_id', $vk_post_id);
        }
    }
}

// Функция для получения сервера загрузки фотографий
function vk_get_upload_server($access_token, $group_id) {
    $params = [
        'access_token' => $access_token,
        'v' => '5.131',
        'group_id' => $group_id,
    ];
    $response = wp_remote_get('https://api.vk.com/method/photos.getWallUploadServer?' . http_build_query($params));
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    return $result['response']['upload_url'] ?? false;
}

// Функция для загрузки фотографии на сервер ВК с использованием ключа доступа сообщества
function vk_upload_photo($access_token, $group_id, $upload_url, $image_url) {
    $image = wp_remote_get($image_url, array('timeout' => 60));

    if (is_wp_error($image)) {
        return false;
    }

    $image_body = wp_remote_retrieve_body($image);

    // Сохраняем изображение во временный файл
    $tmp_file = wp_tempnam($image_url);
    if (!$tmp_file) {
        return false;
    }
    file_put_contents($tmp_file, $image_body);

    // Подготавливаем файл для отправки
    $curl_file = curl_file_create($tmp_file, mime_content_type($tmp_file), basename($tmp_file));

    // Подготавливаем данные для отправки
    $post_fields = array('photo' => $curl_file);

    // Инициализируем cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $upload_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Выполняем запрос
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    // Удаляем временный файл
    unlink($tmp_file);

    if ($response === false) {
        return false;
    }

    $upload_result = json_decode($response, true);

    if (!isset($upload_result['photo'])) {
        return false;
    }

    // Сохраняем фотографию на стене группы
    $params = array(
        'access_token' => $access_token,
        'v'            => '5.131',
        'group_id'     => $group_id,
        'server'       => $upload_result['server'],
        'photo'        => $upload_result['photo'],
        'hash'         => $upload_result['hash'],
    );

    $save_response = wp_remote_post('https://api.vk.com/method/photos.saveWallPhoto', array(
        'body' => $params,
    ));

    if (is_wp_error($save_response)) {
        return false;
    }

    $save_body = wp_remote_retrieve_body($save_response);
    $save_result = json_decode($save_body, true);

    if (isset($save_result['response'][0])) {
        return $save_result['response'][0];
    }

    return false;
}

// Функция для публикации нового поста в ВК
function vk_publish_post($access_token, $group_id, $message, $attachments = []) {
    $params = [
        'access_token' => $access_token,
        'v' => '5.131',
        'owner_id' => '-' . $group_id,
        'from_group' => 1,
        'message' => $message,
    ];
    if (!empty($attachments)) {
        $params['attachments'] = implode(',', $attachments);
    }
    $response = wp_remote_post('https://api.vk.com/method/wall.post', [
        'body' => $params,
    ]);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    return $result['response']['post_id'] ?? false;
}

// Функция для обновления существующего поста в ВК
function vk_edit_post($access_token, $group_id, $vk_post_id, $message, $attachments = []) {
    $params = [
        'access_token' => $access_token,
        'v' => '5.131',
        'owner_id' => '-' . $group_id,
        'post_id' => $vk_post_id,
        'message' => $message,
    ];
    if (!empty($attachments)) {
        $params['attachments'] = implode(',', $attachments);
    }
    $response = wp_remote_post('https://api.vk.com/method/wall.edit', [
        'body' => $params,
    ]);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    return isset($result['response']) && $result['response'] == 1;
}
