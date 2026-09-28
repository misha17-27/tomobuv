<?php
/**
 * Общие компоненты редакторов для экранов, контроллер которых их не подключил (товар, категория, бренд, характеристика):
 * content.js — HTML-редактор textarea[data-editor] и переключатель «RU | UA»; media.js — медиатека («Медиатека» в редакторе,
 * «Выбрать из медиатеки»). Стили компонентов — в admin.css. Скрипты с defer выполняются после скриптов из <head>.
 * @var ?array $scripts — скрипты экрана из render()
 */
foreach (['admin/content.js', 'admin/media.js'] as $js):
    if (in_array($js, $scripts ?? [], true)) continue; ?>
<script src="<?= e(asset($js)) ?>" defer></script>
<?php endforeach; ?>
