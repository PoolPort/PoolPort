<html>
    <body>
        <script>
        	var form = document.createElement("form");
        	form.setAttribute("method", "POST");
        	form.setAttribute("action", "https://sep.shaparak.ir/OnlinePG/OnlinePG");
        	form.setAttribute("target", "_self");

            var hiddenField = document.createElement("input");
        	hiddenField.setAttribute("name", "Token");
        	hiddenField.setAttribute("value", "<?php echo $token ?>");

            form.appendChild(hiddenField);

        	document.body.appendChild(form);
        	form.submit();
        	document.body.removeChild(form);
        </script>
    </body>
</html>
