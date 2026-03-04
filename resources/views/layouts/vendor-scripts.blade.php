<!-- JAVASCRIPT -->
<script src="{{ URL::asset('build/libs/jquery/jquery.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/metismenu/metisMenu.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/node-waves/waves.min.js') }}"></script>

@yield('script')

<!-- DataTables -->
<script src="{{ URL::asset('build/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-buttons/js/dataTables.buttons.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-buttons-bs4/js/buttons.bootstrap4.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/jszip/jszip.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/pdfmake/build/pdfmake.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/pdfmake/build/vfs_fonts.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-buttons/js/buttons.html5.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-buttons/js/buttons.print.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-buttons/js/buttons.colVis.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>

<!-- SweetAlert2 -->
<script src="{{ URL::asset('build/libs/sweetalert2/sweetalert2.min.js') }}"></script>

<!-- Toastr -->
<script src="{{ URL::asset('build/libs/toastr/build/toastr.min.js') }}"></script>

<!-- App js -->
<script src="{{ URL::asset('build/js/app.js') }}"></script>

<!-- ecom360 shared API client -->
<script src="{{ URL::asset('js/ecom360-api.js') }}"></script>

@if(session('success'))
<script>
    toastr.success("{{ session('success') }}", "Success");
</script>
@endif
@if(session('error'))
<script>
    toastr.error("{{ session('error') }}", "Error");
</script>
@endif

@yield('script-bottom')
