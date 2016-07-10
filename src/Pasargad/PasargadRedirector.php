<html>
    <body>
        <form id="form" action="https://pep.shaparak.ir/gateway.aspx" method="post">
            <input type='hidden' name='terminalCode' value='<?php echo $fields['terminalCode'] ?>'/>
            <input type='hidden' name='merchantCode' value='<?php echo $fields['merchantCode'] ?>'/>
            <input type='hidden' name='redirectAddress' value='<?php echo $fields['redirectAddress'] ?>'/>
            <input type='hidden' name='timeStamp' value='<?php echo $fields['timeStamp'] ?>'/>
            <input type='hidden' name='invoiceDate' value='<?php echo $fields['invoiceDate'] ?>'/>
            <input type='hidden' name='action' value='<?php echo $fields['action'] ?>'/>
            <input type='hidden' name='amount' value='<?php echo $fields['amount'] ?>'/>
            <input type='hidden' name='invoiceNumber' value='<?php echo $fields['invoiceNumber'] ?>'/>
            <input type='hidden' name='sign' value='<?php echo $fields['sign'] ?>'/>
        </form>
        <script>
        	var form = document.getElementById("form");
        	form.submit();
        </script>
    </body>
</html>
