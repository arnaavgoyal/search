function search_request() {
    search_val = document.getElementById('searchbox').value;
    const xhttp = new XMLHttpRequest();
    xhttp.onload = () => {
        document.getElementById('resultbox').innerHTML = xhttp.responseText;
    }
    xhttp.open("GET", "search.php?q=" + encodeURIComponent(search_val));
    xhttp.responseType = "text";
    xhttp.send();
}