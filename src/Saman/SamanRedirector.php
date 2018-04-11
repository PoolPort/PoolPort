<html>
    <body>
        <script>
        	var form = document.createElement("form");
        	form.setAttribute("method", "POST");
        	form.setAttribute("action", "https://sep.shaparak.ir/payment.aspx");
        	form.setAttribute("target", "_self");

            var hiddenField = document.createElement("input");
        	hiddenField.setAttribute("name", "Token");
        	hiddenField.setAttribute("value", "<?php echo $token ?>");

            var hiddenField2 = document.createElement("input");
        	hiddenField2.setAttribute("name", "RedirectURL");
        	hiddenField2.setAttribute("value", "<?php echo $callbackUrl ?>");

            form.appendChild(hiddenField);
            form.appendChild(hiddenField2);

        	document.body.appendChild(form);
        	form.submit();
        	document.body.removeChild(form);
        </script>
    </body>
</html>
