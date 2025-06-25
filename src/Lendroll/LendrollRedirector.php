<html>
<body>
<form id="form" action='<?php echo $fields['url'] ?>' method="post">
    <input type='hidden' name='Authority' value='<?php echo $fields['Authority'] ?>'/>
</form>
<script>
    var form = document.getElementById("form");
    form.submit();
</script>
</body>
</html>
