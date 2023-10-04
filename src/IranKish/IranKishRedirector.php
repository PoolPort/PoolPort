<html>
    <body>
        <script>
        	var form = document.createElement("form");
        	form.setAttribute("method", "POST");
        	form.setAttribute("action", "https://ikc.shaparak.ir/iuiv3/IPG/Index/");
        	form.setAttribute("target", "_self");
        	form.setAttribute("enctype", "multipart/form-data");

            var hiddenField = document.createElement("input");
        	hiddenField.setAttribute("name", "tokenIdentity");
        	hiddenField.setAttribute("value", "<?php echo $token ?>");

            form.appendChild(hiddenField);

        	document.body.appendChild(form);
        	form.submit();
        	document.body.removeChild(form);
        </script>
    </body>
</html>
