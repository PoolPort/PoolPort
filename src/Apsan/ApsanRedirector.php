<html>
<body>
<form id="form" action="https://pay.apsan.co/v1/payment" method="post">
    <input type='hidden' name='token' value='<?php echo $fields['token'] ?>'/>
</form>
<script>
    var form = document.getElementById("form");
    form.submit();
</script>
</body>
</html>
