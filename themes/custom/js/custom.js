/* Add your custom template javascript here */

function finnaCustomInit() {
    var allParas = document.getElementsByClassName('dateyeartype');
    //filter by class name if desired...
    for(var i=0;i<allParas.length;i++) {
        if(allParas[i].getElementsByTagName('*').length == 0){
            allParas[i].style.display = 'none';
        }
    }
}
