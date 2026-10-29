import 'package:flutter/material.dart';
import '../../models/species.dart';
import '../../data/database_helper.dart';

class SpeciesCatalogScreen extends StatefulWidget {
  const SpeciesCatalogScreen({super.key});

  @override
  State<SpeciesCatalogScreen> createState() => _SpeciesCatalogScreenState();
}

class _SpeciesCatalogScreenState extends State<SpeciesCatalogScreen> {
  final DatabaseHelper _db = DatabaseHelper.instance;
  final TextEditingController _searchController = TextEditingController();

  List<SpeciesItem> _species = [];
  String _selectedRisk = 'all';
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadSpecies();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadSpecies() async {
    final list = await _db.getCachedSpecies(
      query: _searchController.text,
      riskFilter: _selectedRisk,
    );

    if (mounted) {
      setState(() {
        _species = list;
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Verified Species Catalog'),
        backgroundColor: Colors.blueGrey.shade800,
        foregroundColor: Colors.white,
      ),
      body: Column(
        children: [
          // Search & Filter Header
          Container(
            color: Colors.blueGrey.shade50,
            padding: const EdgeInsets.all(12.0),
            child: Column(
              children: [
                TextField(
                  controller: _searchController,
                  decoration: InputDecoration(
                    hintText: 'Search by Kiribati or English name...',
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear),
                            onPressed: () {
                              _searchController.clear();
                              _loadSpecies();
                            },
                          )
                        : null,
                    filled: true,
                    fillColor: Colors.white,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(24),
                      borderSide: BorderSide.none,
                    ),
                  ),
                  onChanged: (_) => _loadSpecies(),
                ),
                const SizedBox(height: 8),
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      const Text(
                        'CFP Risk: ',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(width: 4),
                      _buildRiskChip('all', 'All'),
                      const SizedBox(width: 6),
                      _buildRiskChip('low', 'Low Risk'),
                      const SizedBox(width: 6),
                      _buildRiskChip('medium', 'Medium Risk'),
                      const SizedBox(width: 6),
                      _buildRiskChip('high', 'High Risk'),
                    ],
                  ),
                ),
              ],
            ),
          ),

          // Species Count & Offline Indicator
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 8.0),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  '${_species.length} species available offline',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                ),
                Row(
                  children: [
                    Icon(Icons.offline_pin_rounded, size: 16, color: Colors.teal.shade700),
                    const SizedBox(width: 4),
                    Text(
                      'SQLite Offline Cache',
                      style: TextStyle(
                        color: Colors.teal.shade700,
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // List of Species Cards
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _species.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.search_off_rounded, size: 48, color: Colors.grey.shade400),
                            const SizedBox(height: 8),
                            Text(
                              'No matching fish species found',
                              style: TextStyle(color: Colors.grey.shade600),
                            ),
                          ],
                        ),
                      )
                    : ListView.builder(
                        itemCount: _species.length,
                        itemBuilder: (context, index) {
                          final sp = _species[index];
                          return _buildSpeciesCard(sp);
                        },
                      ),
          ),
        ],
      ),
    );
  }

  Widget _buildRiskChip(String riskKey, String label) {
    final isSelected = _selectedRisk == riskKey;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      onSelected: (selected) {
        if (selected) {
          setState(() {
            _selectedRisk = riskKey;
          });
          _loadSpecies();
        }
      },
    );
  }

  Widget _buildSpeciesCard(SpeciesItem sp) {
    Color riskColor;
    switch (sp.ciguateraRisk.toLowerCase()) {
      case 'high':
        riskColor = Colors.red;
        break;
      case 'medium':
        riskColor = Colors.orange;
        break;
      default:
        riskColor = Colors.teal;
    }

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 6.0),
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Specimen Thumbnail
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: Colors.blueGrey.shade100,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: sp.photoUrl != null && sp.photoUrl!.isNotEmpty
                      ? ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.network(
                            sp.photoUrl!,
                            fit: BoxFit.cover,
                            errorBuilder: (_, _, _) => const Icon(Icons.phishing, color: Colors.blueGrey),
                          ),
                        )
                      : const Icon(Icons.phishing, color: Colors.blueGrey),
                ),
                const SizedBox(width: 12),
                // Title and taxonomy
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              sp.kiribatiName,
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                                color: Colors.black87,
                              ),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                            decoration: BoxDecoration(
                              color: riskColor.withValues(alpha: 0.15),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: riskColor.withValues(alpha: 0.5)),
                            ),
                            child: Text(
                              '${sp.ciguateraRisk.toUpperCase()} CFP',
                              style: TextStyle(
                                color: riskColor,
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text(
                        sp.englishName,
                        style: TextStyle(fontSize: 14, color: Colors.grey.shade800),
                      ),
                      Text(
                        sp.scientificName,
                        style: TextStyle(
                          fontSize: 12,
                          fontStyle: FontStyle.italic,
                          color: Colors.grey.shade600,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (sp.visualFeatures.isNotEmpty) ...[
              const SizedBox(height: 8),
              const Divider(height: 12),
              const Text(
                'AI Morphological Traits (Verified Reference):',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blueGrey),
              ),
              const SizedBox(height: 4),
              Wrap(
                spacing: 6,
                runSpacing: 4,
                children: sp.visualFeatures.map((trait) {
                  return Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: Colors.grey.shade300),
                    ),
                    child: Text(
                      '• $trait',
                      style: TextStyle(fontSize: 11, color: Colors.grey.shade800),
                    ),
                  );
                }).toList(),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
