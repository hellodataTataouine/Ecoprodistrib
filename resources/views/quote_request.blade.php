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
    <h1>Cher(e) Admin ,</h1>
    <h2>Email Client : {{ $from }}</h2>
    <h2>Je vous contacte pour demander un devis pour les produits ci-dessous que nous souhaitons acheter. Attaché à cet e-mail, vous trouverez un tableau détaillant les produits.</h2>

<table style="font-size: 18px; width: 100%;">
    <thead>
        <tr>
            <th>Produit</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($cart as $item)

                <tr>
                    <td>{{ $item['name'] }}</td>
                </tr>

        @endforeach
    </tbody>
</table>

    <p style="font-size: 16px;">Cordialement</p>
</body>
</html>
