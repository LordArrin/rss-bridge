<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS-Bridge Connectivity Check</title>
    <link rel="stylesheet" href="static/connectivity.css">
    <script src="static/connectivity.js" type="text/javascript"></script>
</head>
<body>
<div id="main-content" class="container">
    <div class="progress">
        <div class="progress-bar" role="progressbar" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <div id="status-message" class="sticky-top alert alert-primary alert-dismissible fade show" role="alert">
        <i id="status-icon" class="fas fa-sync"></i>
        <span>...</span>
        <button type="button" class="close" aria-label="Close" onclick="stopConnectivityChecks()">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <input type="text" class="form-control" id="search" onkeyup="search()" placeholder="Search for bridge..">
</div>
</body>
</html>