<form method="POST" action="{{ route('feed.delete_products', $entry->getKey()) }}" style="display: inline;">
    @csrf
    <button type="submit" class="btn btn-sm btn-link" data-button-type="delete-products" onclick="return confirm('Are you sure you want to delete all products from this feed? This action cannot be undone.')">
        <i class="la la-trash"></i> Delete Products
    </button>
</form>
