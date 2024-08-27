<html>
<body>
<form id="form" action="<?php
echo $fields['url'] ?>" method="post">
    <input type='hidden' name='username' value='<?php
    echo $fields['username'] ?>'/>
    <input type='hidden' name='token' value='<?php
    echo $fields['token'] ?>'/>
</form>
<script>
    var form = document.getElementById('form');
    form.submit();
</script>
</body>
</html>
