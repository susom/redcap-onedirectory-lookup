<?php

namespace Stanford\RedcapOneDirectoryLookup;

/** @var \Stanford\RedcapOneDirectoryLookup\RedcapOneDirectoryLookup $this */

?>
<script src="//ajax.googleapis.com/ajax/libs/jquery/3.4.0/jquery.min.js"></script>
<link rel="stylesheet" href="//code.jquery.com/ui/1.12.0/themes/base/jquery-ui.css">
<script src="//code.jquery.com/ui/1.12.0/jquery-ui.js"></script>
<link rel="stylesheet" type="text/css" href="//cdnjs.cloudflare.com/ajax/libs/font-awesome/5.8.2/css/all.min.css">

<script src="<?php echo $this->getUrl("assets/js/fields.js", true, true) ?>"></script>
<script>
    Fields.list = <?php echo json_encode($this->getFieldsMap()) ?>
</script>