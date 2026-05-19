


$(document).ready(function () {
    const url = new URLSearchParams(window.location.search);
    const qs = url.get('sube');
    switch (qs)
    {
        case "akdAfyon":{
            document.getElementById("akdAfyon").scrollIntoView(true);
        
            break;
        }
        case "ikdAfyon": {

            document.getElementById("ikdAfyon").scrollIntoView(true);
            break;
        }
       default:{

          
            break;
        }

    }
   
});