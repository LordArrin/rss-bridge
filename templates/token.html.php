<section class="token-page">
    <h1>Authentication Required</h1>
    
    <p class="token-message">
        <?= e($message) ?>
    </p>

    <form action="" method="get" autocomplete="off" class="token-form">
        <div class="parameters">
            <label for="token">Token</label>
            <input 
                type="password" 
                name="token" 
                id="token" 
                placeholder="Enter your authentication token" 
                value="<?= e($token) ?>"
                autocomplete="off"
                required
            >
            <i class="info" title="Your admin authentication token from config.ini.php"></i>
        </div>
        
        <button type="submit">Authenticate</button>
    </form>
</section>