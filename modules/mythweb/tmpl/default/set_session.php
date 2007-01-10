<?php
/**
 * Configure MythWeb Session info
 *
 * @url         $URL$
 * @date        $Date$
 * @version     $Revision$
 * @author      $Author$
 * @license     GPL
 *
 * @package     MythWeb
 * @subpackage  Settings
 *
/**/
?>

<form class="form" method="post" action="<?php echo form_action ?>">

<table border="0" cellspacing="0" cellpadding="0">
<tr class="_sep">
    <th><?php echo t('MythWeb Template') ?>:</th>
    <td><?php template_select() ?></td>
</tr><tr class="_sep">
    <th><?php echo t('MythWeb Skin') ?>:</th>
    <td><?php skin_select() ?></td>
</tr><tr class="_sep">
    <th><?php echo t('Language') ?>:</th>
    <td><?php language_select() ?></td>
</tr><tr>
    <td align="center"><input type="reset"  class="submit" value="<?php echo t('Reset') ?>"></td>
    <td align="center"><input type="submit" class="submit" name="save" value="<?php echo t('Save') ?>"></td>
</tr>
</table>

</form>
