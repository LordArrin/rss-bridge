'use strict';

const remote = location.href.substring(0, location.href.lastIndexOf("/"));
let bridges = [];
let abort = false;
let searchTimeout = null;

document.addEventListener('DOMContentLoaded', function() {
    fetchBridgeList();
    
    // Add event listener for search input
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(search, 150);
        });
    }
});

async function fetchBridgeList() {
    try {
        const response = await fetch(remote + '/?action=list');
        if (!response.ok) {
            throw new Error('Failed to fetch bridge list: ' + response.status);
        }
        const data = await response.text();
        processBridgeList(data);
    } catch (error) {
        console.error('Error fetching bridge list:', error);
        showError('Failed to load bridge list. Please refresh the page.');
    }
}

function showError(message) {
    const msg = document.getElementById('status-message');
    if (msg) {
        msg.classList.remove('alert-primary');
        msg.classList.add('alert-danger');
        msg.getElementsByTagName('span')[0].textContent = message;
    }
}

function processBridgeList(data) {
    try {
        const list = JSON.parse(data);
        buildTable(list);
        buildBridgeQueue(list);
        checkNextBridgeAsync();
    } catch (error) {
        console.error('Error processing bridge list:', error);
        showError('Invalid bridge list data received.');
    }
}

function buildTable(bridgeList) {
    const table = document.createElement('table');
    table.classList.add('table');

    const thead = document.createElement('thead');
    thead.innerHTML = `
    <tr>
        <th scope="col">Bridge</th>
        <th scope="col">Result</th>
    </tr>`;

    const tbody = document.createElement('tbody');

    for (const bridge in bridgeList.bridges) {
        const tr = document.createElement('tr');
        tr.classList.add('bg-secondary');
        tr.id = bridge;

        const td_bridge = document.createElement('td');
        td_bridge.textContent = bridgeList.bridges[bridge].name;

        // Link to the actual bridge on frontpage
        const a = document.createElement('a');
        a.href = remote + "/#bridge-" + bridge;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = '[Show]';
        a.style.marginLeft = '5px';
        a.style.color = 'inherit';

        td_bridge.appendChild(a);
        tr.appendChild(td_bridge);

        const td_result = document.createElement('td');

        if (bridgeList.bridges[bridge].status === 'active') {
            td_result.innerHTML = '<i title="Scheduled" class="fas fa-hourglass-start"></i>';
        } else {
            td_result.innerHTML = '<i title="Inactive" class="fas fa-times-circle"></i>';
        }

        tr.appendChild(td_result);
        tbody.appendChild(tr);
    }

    table.appendChild(thead);
    table.appendChild(tbody);

    const content = document.getElementById('main-content');
    if (content) {
        content.appendChild(table);
    }
}

function buildBridgeQueue(bridgeList) {
    for (const bridge in bridgeList.bridges) {
        if (bridgeList.bridges[bridge].status !== 'active') {
            continue;
        }
        bridges.push(bridge);
    }
}

async function checkNextBridgeAsync() {
    const msg = document.getElementById('status-message');
    const icon = document.getElementById('status-icon');

    if (!msg || !icon) {
        return;
    }

    if (bridges.length === 0) {
        msg.classList.remove('alert-primary');
        msg.classList.add('alert-success');
        msg.getElementsByTagName('span')[0].textContent = 'Done';

        icon.classList.remove('fa-sync');
        icon.classList.add('fa-check');
        return;
    }

    const bridge = bridges.shift();
    msg.getElementsByTagName('span')[0].textContent = 'Processing ' + bridge + '...';

    try {
        const response = await fetch(remote + '/?action=Connectivity&bridge=' + bridge);
        if (!response.ok) {
            throw new Error('Bridge check failed: ' + response.status);
        }
        
        const data = await response.text();
        const result = JSON.parse(data);
        
        if (result.successful) {
            markBridgeSuccessful(result);
        } else {
            markBridgeFailed(result);
        }
    } catch (error) {
        console.error('Error checking bridge ' + bridge + ':', error);
        markBridgeFailed({ bridge: bridge, error: error.message });
    }

    if (abort) {
        abortChecks();
        return;
    }

    search(); // Dynamically update search results
    updateProgressBar();
    checkNextBridgeAsync();
}

function abortChecks() {
    const msg = document.getElementById('status-message');
    if (!msg) {
        return;
    }

    msg.classList.remove('alert-primary');
    msg.classList.add('alert-warning');
    msg.getElementsByTagName('span')[0].textContent = 'Aborted';

    const icon = document.getElementById('status-icon');
    if (icon) {
        icon.classList.remove('fa-sync');
        icon.classList.add('fa-ban');
    }

    bridges.forEach((bridge) => {
        markBridgeAborted(bridge);
    });
}

function markBridgeSuccessful(result) {
    const tr = document.getElementById(result.bridge);
    if (!tr) {
        return;
    }
    
    tr.classList.remove('bg-secondary');
    if (result.http_code === 200) {
        tr.classList.add('bg-success');
        tr.children[1].innerHTML = '<i title="Successful" class="fas fa-check"></i>';
    } else {
        tr.classList.add('bg-primary');
        tr.children[1].innerHTML = '<i title="Redirected" class="fas fa-directions"></i>';
    }
}

function markBridgeFailed(result) {
    const tr = document.getElementById(result.bridge);
    if (!tr) {
        return;
    }
    
    tr.classList.remove('bg-secondary');
    tr.classList.add('bg-danger');
    tr.children[1].innerHTML = '<i title="Failed" class="fas fa-exclamation-triangle"></i>';
}

function markBridgeAborted(bridge) {
    const tr = document.getElementById(bridge);
    if (!tr) {
        return;
    }
    
    tr.classList.remove('bg-secondary');
    tr.classList.add('bg-warning');
    tr.children[1].innerHTML = '<i title="Aborted" class="fas fa-ban"></i>';
}

function updateProgressBar() {
    const table = document.querySelector('table');
    if (!table) {
        return;
    }

    // This will break if the table changes
    const total = table.getElementsByTagName('tr').length - 1;
    const current = bridges.length;
    const progress = (total - current) * 100 / total;

    const progressBar = document.getElementsByClassName('progress-bar')[0];

    if (progressBar) {
        progressBar.setAttribute('aria-valuenow', progress.toFixed(0));
        progressBar.style.width = progress.toFixed(0) + '%';
    }
}

function stopConnectivityChecks() {
    abort = true;
}

function search() {
    const input = document.getElementById('search');
    const table = document.querySelector('table');
    
    if (!input || !table) {
        return;
    }

    const filter = input.value.toUpperCase();
    const tr = table.getElementsByTagName('tr');

    for (let i = 0; i < tr.length; i++) {
        const td1 = tr[i].getElementsByTagName('td')[0];
        const td2 = tr[i].getElementsByTagName('td')[1];

        if (td1) {
            const txtValue = td1.textContent || td1.innerText;

            let title = '';
            const icon = td2 ? td2.getElementsByTagName('i')[0] : null;
            if (icon) {
                title = icon.title || '';
            }

            if (txtValue.toUpperCase().indexOf(filter) > -1 || 
                title.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = '';
            } else {
                tr[i].style.display = 'none';
            }
        }
    }
}