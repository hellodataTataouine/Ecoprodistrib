<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demande de devis</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 18px;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }

        h1 {
            font-size: 24px;
        }

        h2 {
            font-size: 20px;
        }

        ul {
            list-style: none;
            padding: 0;
        }

        li {
            margin-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }

        p {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <h2>Bonjour M/Mdm {{$toname}}</h2>
    <p>Voici votre lien de vérification <form action="{{$verification_link}}" method="get">
        <button type="submit">Vérifier</button>
    </form></p>
    <p>Cordialement,</p>
</body>
</html>
