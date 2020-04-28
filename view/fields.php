<?php

namespace Stanford\RedcapOneDirectoryLookup;

/** @var \Stanford\RedcapOneDirectoryLookup\RedcapOneDirectoryLookup $this */

?>
<style>
    .ui-autocomplete-loading {
        background: url('<?php echo $this->getUrl("assets/images/progress_circle.gif", true, true) ?>') no-repeat right center;
        background-size: 20px 20px;
        progress_circle . gif
    }

    .ui-autocomplete-input {
        float: left;
        margin-right: 5px;
    }

</style>
<script src="//ajax.googleapis.com/ajax/libs/jquery/3.4.0/jquery.min.js"></script>
<link rel="stylesheet" href="//code.jquery.com/ui/1.12.0/themes/base/jquery-ui.css">
<script src="//code.jquery.com/ui/1.12.0/jquery-ui.js"></script>
<link rel="stylesheet" type="text/css" href="//cdnjs.cloudflare.com/ajax/libs/font-awesome/5.8.2/css/all.min.css">

<input type="hidden" name="get-users-ajax-url" id="get-users-ajax-url"
       value="<?php echo $this->getUrl("ajax/get_users.php", false, false) ?>"/>
<script src="<?php echo $this->getUrl("assets/js/fields.js", true, true) ?>"></script>
<script>
    Fields.image = '<?php echo $this->getUrl("assets/images/magnifier.png", true, true) ?>';
    Fields.list = <?php echo json_encode($this->getFieldsMap()) ?>
</script>