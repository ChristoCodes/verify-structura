<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
</head>

<body>
  <?php

  $books = [
    "Do Androids Dream of Electric Sheep?",
    "The Langoliers",
    "Project Hail Mary
    
    
    
    "
  ];
  ?>

  <ul>

    <?php foreach ($books as $book) : ?>
      <li> <?= $book ?></li>
    <?php endforeach ?>

  </ul>
</body>

</html>
